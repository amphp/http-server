<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamChain;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\Http1\Rfc7230;
use Amp\Http\HttpStatus;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Internal\AbstractHttpDriver;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Amp\Socket\InternetAddress;
use League\Uri;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\async;
use function Amp\Http\formatDateHeader;
use function Amp\Http\mapHeaderPairs;

final class Http1Driver extends AbstractHttpDriver
{
    private static function makeHeaderReduceClosure(string $search): \Closure
    {
        return static fn (bool $carry, string $header) => $carry || \strcasecmp($search, $header) === 0;
    }

    private ?Http2Driver $http2driver = null;

    private Client $client;

    private ReadableStream $readableStream;

    private WritableStream $writableStream;

    private int $pendingResponseCount = 0;

    private ?Queue $bodyQueue = null;

    private Future $pendingResponse;

    private ?Future $lastWrite = null;

    private bool $stopping = false;

    private string $currentBuffer = "";

    private bool $continue = true;

    private readonly DeferredCancellation $deferredCancellation;

    public function __construct(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        private readonly int $connectionTimeout = self::DEFAULT_STREAM_TIMEOUT,
        private readonly int $headerSizeLimit = self::DEFAULT_HEADER_SIZE_LIMIT,
        private readonly int $bodySizeLimit = self::DEFAULT_BODY_SIZE_LIMIT,
        private readonly bool $allowHttp2Upgrade = false,
    ) {
        parent::__construct($requestHandler, $errorHandler, $logger);

        $this->pendingResponse = Future::complete();
        $this->deferredCancellation = new DeferredCancellation();
    }

    public function handleClient(
        Client $client,
        ReadableStream $readableStream,
        WritableStream $writableStream,
    ): void {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(!isset($this->client));

        /** @psalm-suppress RedundantCondition */
        \assert($this->logger->debug(\sprintf(
            "Handling requests from %s #%d using HTTP/1.x driver",
            $client->getRemoteAddress()->toString(),
            $client->getId(),
        )) || true);

        $this->client = $client;
        $this->readableStream = $readableStream;
        $this->writableStream = $writableStream;

        $this->insertTimeout();

        $headerSizeLimit = $this->headerSizeLimit;
        $cancellation = $this->deferredCancellation->getCancellation();

        try {
            $buffer = $readableStream->read($cancellation);
            if ($buffer === null) {
                $this->removeTimeout();
                return;
            }

            do {
                if ($this->http2driver) {
                    $this->suspendTimeout();
                    $this->http2driver->handleClientWithBuffer($buffer, $this->readableStream);
                    return;
                }

                $contentLength = null;
                $isChunked = false;
                $searchPos = 0;
                $rawHeaders = ""; // For Psalm

                $buffer = \ltrim($buffer, "\r\n");

                do {
                    if ($this->stopping) {
                        return;
                    }

                    if ($headerPos = \strpos($buffer, "\r\n\r\n", $searchPos)) {
                        $rawHeaders = \substr($buffer, 0, $headerPos + 2);
                        $buffer = \substr($buffer, $headerPos + 4);
                        break;
                    }

                    if (\strlen($buffer) > $headerSizeLimit) {
                        throw new ClientException(
                            $this->client,
                            "Bad Request: header size violation",
                            HttpStatus::REQUEST_HEADER_FIELDS_TOO_LARGE
                        );
                    }

                    $chunk = $readableStream->read($cancellation);
                    if ($chunk === null) {
                        return;
                    }

                    $searchPos = \max(0, \strlen($buffer) - 3);
                    $buffer .= $chunk;
                } while (true);

                if (!\preg_match("#^([^ ]+) (\S+) HTTP/(\d+(?:\.\d+)?)\r\n#", $rawHeaders, $matches)) {
                    throw new ClientException($this->client, "Bad Request: invalid request line", HttpStatus::BAD_REQUEST);
                }

                /** @var non-empty-list<non-empty-string> $matches */
                [$startLine, $method, $target, $protocol] = $matches;
                $rawHeaders = \substr($rawHeaders, \strlen($startLine));

                if ($protocol !== "1.1" && $protocol !== "1.0") {
                    if ($protocol === "2.0" && $this->allowHttp2Upgrade) {
                        $this->removeTimeout();

                        // Internal upgrade to HTTP/2.
                        $this->http2driver = new Http2Driver(
                            requestHandler: $this->requestHandler,
                            errorHandler: $this->errorHandler,
                            logger: $this->logger,
                            streamTimeout: $this->connectionTimeout,
                            connectionTimeout: $this->connectionTimeout,
                            headerSizeLimit: $this->headerSizeLimit,
                            bodySizeLimit: $this->bodySizeLimit,
                            pushEnabled: false,
                        );

                        $this->http2driver->handleClient(
                            $this->client,
                            new ReadableStreamChain(
                                new ReadableBuffer("$startLine$rawHeaders\r\n$buffer"),
                                $readableStream
                            ),
                            $writableStream
                        );

                        return;
                    }

                    throw new ClientException(
                        $this->client,
                        "Unsupported version $protocol",
                        HttpStatus::HTTP_VERSION_NOT_SUPPORTED
                    );
                }

                if (!$rawHeaders) {
                    throw new ClientException($this->client, "Bad Request: missing host header", HttpStatus::BAD_REQUEST);
                }

                try {
                    $parsedHeaders = Rfc7230::parseHeaderPairs($rawHeaders);
                    $headers = mapHeaderPairs($parsedHeaders);
                } catch (InvalidHeaderException $e) {
                    throw new ClientException(
                        $this->client,
                        "Bad Request: " . $e->getMessage(),
                        HttpStatus::BAD_REQUEST
                    );
                }

                if (isset($headers["content-length"][1])) {
                    throw new ClientException(
                        $this->client,
                        "Bad Request: multiple content-length headers",
                        HttpStatus::BAD_REQUEST
                    );
                }

                $contentLength = $headers["content-length"][0] ?? null;
                if ($contentLength !== null) {
                    if (!\preg_match("/^(?:0|[1-9]\\d*)$/", $contentLength)) {
                        throw new ClientException(
                            $this->client,
                            "Bad Request: invalid content length",
                            HttpStatus::BAD_REQUEST
                        );
                    }

                    $contentLength = (int) $contentLength;
                }

                if (isset($headers["transfer-encoding"])) {
                    $transferEncoding = \strtolower(\implode(', ', $headers["transfer-encoding"]));
                    $isChunked = match ($transferEncoding) {
                        'chunked' => true,
                        'identity' => false,
                        default => throw new ClientException(
                            $this->client,
                            "Unsupported transfer-encoding",
                            HttpStatus::NOT_IMPLEMENTED
                        ),
                    };
                }

                if (!isset($headers["host"][0])) {
                    throw new ClientException($this->client, "Bad Request: missing host header", HttpStatus::BAD_REQUEST);
                }

                if (isset($headers["host"][1])) {
                    throw new ClientException($this->client, "Bad Request: multiple host headers", HttpStatus::BAD_REQUEST);
                }

                if (!\preg_match(self::HOST_HEADER_REGEX, $headers["host"][0], $matches)) {
                    throw new ClientException($this->client, "Bad Request: invalid host header", HttpStatus::BAD_REQUEST);
                }

                $address = $this->client->getLocalAddress();

                $host = $matches[1];
                $port = isset($matches[2])
                    ? (int) $matches[2]
                    : ($address instanceof InternetAddress ? $address->getPort() : null);
                $scheme = $this->client->getTlsInfo() !== null ? "https" : "http";
                $query = null;

                try {
                    if ($target[0] === "/") { // origin-form
                        if ($position = \strpos($target, "#")) {
                            $target = \substr($target, 0, $position);
                        }

                        if ($position = \strpos($target, "?")) {
                            $query = \substr($target, $position + 1);
                            $target = \substr($target, 0, $position);
                        }

                        $uri = Uri\Http::fromComponents([
                            "scheme" => $scheme,
                            "host" => $host,
                            "port" => $port,
                            "path" => $target,
                            "query" => $query,
                        ]);
                    } elseif ($target === "*") { // asterisk-form
                        $uri = Uri\Http::fromComponents([
                            "scheme" => $scheme,
                            "host" => $host,
                            "port" => $port,
                        ]);
                    } elseif (\preg_match("#^https?://#i", $target)) { // absolute-form
                        $uri = Uri\Http::new($target);

                        if ($uri->getHost() !== $host || $uri->getPort() !== $port) {
                            throw new ClientException(
                                $this->client,
                                "Bad Request: target host mis-matched to host header",
                                HttpStatus::BAD_REQUEST
                            );
                        }

                        if ($uri->getPath() === "") {
                            throw new ClientException(
                                $this->client,
                                "Bad Request: no request path provided in target",
                                HttpStatus::BAD_REQUEST
                            );
                        }
                    } else { // authority-form
                        if ($method !== "CONNECT") {
                            throw new ClientException(
                                $this->client,
                                "Bad Request: authority-form only valid for CONNECT requests",
                                HttpStatus::BAD_REQUEST
                            );
                        }

                        if (!\preg_match(self::HOST_HEADER_REGEX, $target, $matches)) {
                            throw new ClientException(
                                $this->client,
                                "Bad Request: invalid connect target",
                                HttpStatus::BAD_REQUEST
                            );
                        }

                        $uri = Uri\Http::fromComponents([
                            "host" => $matches[1],
                            "port" => (int) $matches[2],
                        ]);
                    }
                } catch (Uri\Contracts\UriException $exception) {
                    throw new ClientException(
                        $this->client,
                        "Bad Request: invalid target",
                        HttpStatus::BAD_REQUEST,
                        $exception
                    );
                }

                if (isset($headers["expect"][0]) && \strtolower($headers["expect"][0]) === "100-continue") {
                    $this->write(
                        new Request($this->client, $method, $uri, $headers, '', $protocol),
                        new Response(HttpStatus::CONTINUE, [])
                    );
                }

                // Handle HTTP/2 upgrade request.
                if ($protocol === "1.1"
                    && isset($headers["upgrade"][0], $headers["http2-settings"][0], $headers["connection"][0])
                    && $this->client->getTlsInfo() === null
                    && $this->allowHttp2Upgrade
                    && false !== \stripos($headers["connection"][0], "upgrade")
                    && \strtolower($headers["upgrade"][0]) === "h2c"
                    && false !== $h2cSettings = \base64_decode(
                        \strtr($headers["http2-settings"][0], "-_", "+/"),
                        true
                    )
                ) {
                    // Request instance will be overwritten below. This is for sending the switching protocols response.
                    $this->write(
                        new Request($this->client, $method, $uri, $headers, '', $protocol),
                        new Response(HttpStatus::SWITCHING_PROTOCOLS, [
                            "connection" => "upgrade",
                            "upgrade" => "h2c",
                        ])
                    );

                    $this->removeTimeout();

                    // Internal upgrade
                    $this->http2driver = new Http2Driver(
                        requestHandler: $this->requestHandler,
                        errorHandler: $this->errorHandler,
                        logger: $this->logger,
                        streamTimeout: $this->connectionTimeout,
                        connectionTimeout: $this->connectionTimeout,
                        headerSizeLimit: $this->headerSizeLimit,
                        bodySizeLimit: $this->bodySizeLimit,
                        pushEnabled: false,
                        settings: $h2cSettings,
                    );

                    $this->http2driver->initializeWriting($this->client, $this->writableStream);

                    // Remove headers that are not related to the HTTP/2 request.
                    $parsedHeaders = \array_filter(
                        $parsedHeaders,
                        static fn (array $pair) => match (\strtolower($pair[0])) {
                            'upgrade', 'connection', 'http2-settings' => false,
                            default => true,
                        },
                    );

                    $protocol = "2";
                }

                $this->updateTimeout();

                if (!($isChunked || $contentLength)) {
                    $request = new Request($this->client, $method, $uri, [], '', $protocol);
                    foreach ($parsedHeaders as $pair) {
                        $request->addHeader(...$pair);
                    }

                    $this->pendingResponseCount++;

                    try {
                        if ($this->http2driver) {
                            $this->pendingResponse = async($this->http2driver->handleRequest(...), $request);

                            continue;
                        }

                        $this->suspendTimeout();

                        $this->currentBuffer = $buffer;
                        $this->pendingResponse = async($this->handleRequest(...), $request);
                        $this->pendingResponse->await();
                        $this->pendingResponseCount--;

                        continue;
                    } finally {
                        $request = null; // DO NOT leave a reference to the Request object in the parser!
                    }
                }

                // HTTP/1.x clients only ever have a single body emitter.
                $this->bodyQueue = $queue = new Queue();
                $trailerDeferred = new DeferredFuture;
                $bodySizeLimit = $this->bodySizeLimit;

                $body = new RequestBody(
                    new ReadableIterableStream($queue->pipe()),
                    static function (int $bodySize) use (&$bodySizeLimit): void {
                        if ($bodySize > $bodySizeLimit) {
                            $bodySizeLimit = $bodySize;
                        }
                    }
                );

                try {
                    $trailers = new Trailers(
                        $trailerDeferred->getFuture(),
                        isset($headers['trailers'])
                            ? \array_map('trim', \explode(',', \implode(',', $headers['trailers'])))
                            : []
                    );
                } catch (InvalidHeaderException $exception) {
                    throw new ClientException(
                        $this->client,
                        "Invalid header field in trailers",
                        HttpStatus::BAD_REQUEST,
                        $exception
                    );
                }

                $request = new Request($this->client, $method, $uri, [], $body, $protocol, $trailers);
                foreach ($parsedHeaders as $pair) {
                    $request->addHeader(...$pair);
                }

                // Do not await future until body is completely read.
                $this->pendingResponseCount++;
                $this->currentBuffer = $buffer;
                $this->pendingResponse = async(
                    $this->http2driver
                        ? $this->http2driver->handleRequest(...)
                        : $this->handleRequest(...),
                    $request,
                );

                // DO NOT leave a reference to the Request, Trailers, or Body objects within the parser!
                $request = null;
                $trailers = null;
                $body = "";

                $bodySize = 0;

                if ($isChunked) {
                    while (true) {
                        $lineEndPos = 0; // For Psalm
                        while (false === ($lineEndPos = \strpos($buffer, "\r\n"))) {
                            if (\strlen($buffer) > 10) {
                                throw new ClientException(
                                    $this->client,
                                    "Bad Request: hex chunk size expected",
                                    HttpStatus::BAD_REQUEST
                                );
                            }

                            $chunk = $this->readableStream->read($cancellation);
                            if ($chunk === null) {
                                return;
                            }

                            $buffer .= $chunk;
                        }

                        /** @psalm-suppress NoValue $lineEndPos is set above, maybe a bug in Psalm? */
                        $line = \substr($buffer, 0, $lineEndPos);
                        $buffer = \substr($buffer, $lineEndPos + 2);
                        $hex = \trim($line);
                        if ($hex !== "0") {
                            $hex = \ltrim($line, "0");

                            if (!\preg_match("/^[1-9A-F][0-9A-F]*$/i", $hex)) {
                                throw new ClientException(
                                    $this->client,
                                    "Bad Request: invalid hex chunk size",
                                    HttpStatus::BAD_REQUEST
                                );
                            }
                        }

                        $chunkLengthRemaining = \hexdec($hex);

                        if ($chunkLengthRemaining === 0) {
                            while (!isset($buffer[1])) {
                                $chunk = $readableStream->read($cancellation);
                                if ($chunk === null) {
                                    return;
                                }

                                $buffer .= $chunk;
                            }

                            $firstTwoBytes = \substr($buffer, 0, 2);
                            if ($firstTwoBytes === "\r\n") {
                                $buffer = \substr($buffer, 2);
                                break; // finished, no trailers (chunked loop)
                            }

                            // Note that the Trailer header does not need to be set for the message to include trailers.
                            // @see https://tools.ietf.org/html/rfc7230#section-4.4

                            do {
                                if ($trailerPos = \strpos($buffer, "\r\n\r\n")) {
                                    $rawTrailers = \substr($buffer, 0, $trailerPos + 2);
                                    $buffer = \substr($buffer, $trailerPos + 4);
                                    break;
                                }

                                if (\strlen($buffer) > $headerSizeLimit) {
                                    throw new ClientException(
                                        $this->client,
                                        "Bad Request: trailer headers too large",
                                        HttpStatus::BAD_REQUEST
                                    );
                                }

                                $chunk = $this->readableStream->read($cancellation);
                                if ($chunk === null) {
                                    return;
                                }

                                $buffer .= $chunk;
                            } while (true);

                            \assert(isset($rawTrailers)); // For Psalm

                            try {
                                $trailers = Rfc7230::parseHeaders($rawTrailers);
                            } catch (InvalidHeaderException $e) {
                                throw new ClientException(
                                    $this->client,
                                    "Bad Request: " . $e->getMessage(),
                                    HttpStatus::BAD_REQUEST
                                );
                            }

                            if (\array_intersect_key($trailers, Trailers::DISALLOWED_TRAILERS)) {
                                throw new ClientException(
                                    $this->client,
                                    "Trailer section contains disallowed headers",
                                    HttpStatus::BAD_REQUEST
                                );
                            }

                            $trailerDeferred->complete($trailers);
                            $trailerDeferred = null;

                            break; // finished (chunked loop)
                        }

                        if ($bodySize + $chunkLengthRemaining > $bodySizeLimit) {
                            do {
                                $remaining = $bodySizeLimit - $bodySize;
                                $chunkLengthRemaining -= $remaining - \strlen($body);
                                $body .= $buffer;
                                $bodyBufferSize = \strlen($body);

                                while ($bodyBufferSize < $remaining) {
                                    if ($bodyBufferSize) {
                                        $this->updateTimeout();
                                        try {
                                            $queue->push($body);
                                        } catch (DisposedException) {
                                            // Ignore and continue consuming body.
                                        }
                                        $bodySize += $bodyBufferSize;
                                        $remaining -= $bodyBufferSize;
                                    }

                                    $body = $readableStream->read($cancellation);
                                    if ($body === null) {
                                        return;
                                    }

                                    $bodyBufferSize = \strlen($body);
                                }

                                if ($remaining) {
                                    $this->updateTimeout();
                                    try {
                                        $queue->push(\substr($body, 0, $remaining));
                                    } catch (DisposedException) {
                                        // Ignore and continue consuming body.
                                    }
                                    $buffer = \substr($body, $remaining);
                                    $body = "";
                                    $bodySize += $remaining;
                                }

                                if ($bodySize !== $bodySizeLimit) {
                                    continue;
                                }

                                throw new ClientException(
                                    $this->client,
                                    "Payload too large",
                                    HttpStatus::PAYLOAD_TOO_LARGE
                                );
                            } while ($bodySizeLimit < $bodySize + $chunkLengthRemaining);
                        }

                        while (true) {
                            $bufferLength = \strlen($buffer);

                            if (!$bufferLength) {
                                $chunk = $readableStream->read($cancellation);
                                if ($chunk === null) {
                                    return;
                                }

                                $buffer = $chunk;
                                $bufferLength = \strlen($buffer);
                            }

                            // These first two (extreme) edge cases prevent errors where the packet boundary ends after
                            // the \r and before the \n at the end of a chunk.
                            if ($bufferLength === $chunkLengthRemaining || $bufferLength === $chunkLengthRemaining + 1) {
                                $chunk = $readableStream->read($cancellation);
                                if ($chunk === null) {
                                    return;
                                }

                                $buffer .= $chunk;
                                continue;
                            }

                            $this->updateTimeout();

                            if ($bufferLength >= $chunkLengthRemaining + 2) {
                                try {
                                    $queue->push(\substr($buffer, 0, $chunkLengthRemaining));
                                } catch (DisposedException) {
                                    // Ignore and continue consuming body.
                                }
                                $buffer = \substr($buffer, $chunkLengthRemaining + 2);

                                $chunkLengthRemaining = null;
                                continue 2; // next chunk (chunked loop)
                            }

                            try {
                                $queue->push($buffer);
                            } catch (DisposedException) {
                                // Ignore and continue consuming body.
                            }
                            $buffer = "";
                            $chunkLengthRemaining -= $bufferLength;
                        }
                    }

                    if ($body !== "") {
                        try {
                            $queue->push($body);
                        } catch (DisposedException) {
                            // Ignore and continue consuming body.
                        }
                    }
                } else {
                    do {
                        $bodyBufferSize = \strlen($buffer);

                        // Note that $bodySizeLimit may change while looping.
                        while ($bodySize + $bodyBufferSize < \min($bodySizeLimit, $contentLength)) {
                            if ($bodyBufferSize) {
                                $this->updateTimeout();
                                try {
                                    $queue->push($buffer);
                                } catch (DisposedException) {
                                    // Ignore and continue consuming body.
                                }
                                $buffer = '';
                                $bodySize += $bodyBufferSize;
                            }

                            $chunk = $readableStream->read($cancellation);
                            if ($chunk === null) {
                                return;
                            }

                            $buffer .= $chunk;
                            $bodyBufferSize = \strlen($buffer);
                        }

                        $remaining = \min($bodySizeLimit, $contentLength) - $bodySize;

                        if ($remaining) {
                            $this->updateTimeout();
                            try {
                                $queue->push(\substr($buffer, 0, $remaining));
                            } catch (DisposedException) {
                                // Ignore and continue consuming body.
                            }
                            $buffer = \substr($buffer, $remaining);
                            $bodySize += $remaining;
                        }
                        // handle the case where $bodySizeLimit was increased during the $remaining sequence
                    } while ($bodySize < \min($bodySizeLimit, $contentLength));

                    if ($contentLength > $bodySizeLimit) {
                        throw new ClientException($this->client, "Payload too large", HttpStatus::PAYLOAD_TOO_LARGE);
                    }
                }

                /** @psalm-suppress TypeDoesNotContainNull, RedundantCondition $trailerDeferred could be null */
                $trailerDeferred?->complete([]);
                $trailerDeferred = null;

                $this->bodyQueue = null;
                $queue->complete();

                $this->suspendTimeout();

                if ($this->http2driver) {
                    continue;
                }

                $this->pendingResponse->await(); // Wait for response to be generated.
                $this->pendingResponseCount--;
            } while ($this->continue);
        } catch (ClientException $exception) {
            if ($this->bodyQueue === null || !$this->pendingResponseCount) {
                // Send an error response only if another response has not already been sent to the request.
                $this->sendErrorResponse($exception, $request ?? null)->await();
            }
        } catch (StreamException) {
            // Client disconnected, finally block will clean up.
        } catch (CancelledException) {
            // Server shutting down.
            if ($this->bodyQueue === null || !$this->pendingResponseCount) {
                // Send a service unavailable response only if another response has not already been sent.
                $this->sendServiceUnavailableResponse($request ?? null)->await();
            }
        } finally {
            $this->pendingResponse->finally(function (): void {
                $this->removeTimeout();

                /** @psalm-suppress RedundantCondition */
                \assert($this->logger->debug(\sprintf(
                    "Stopping HTTP/1.x parser @ %s #%d",
                    $this->client->getRemoteAddress()->toString(),
                    $this->client->getId(),
                )) || true);
            })->ignore();

            $this->bodyQueue?->error($exception ??= new ClientException(
                $this->client,
                "Client disconnected",
                HttpStatus::REQUEST_TIMEOUT
            ));
            $this->bodyQueue = null;

            /** @psalm-suppress TypeDoesNotContainType, RedundantCondition */
            ($trailerDeferred ?? null)?->error($exception ??= new ClientException(
                $this->client,
                "Client disconnected",
                HttpStatus::REQUEST_TIMEOUT
            ));
            $trailerDeferred = null;

            $this->deferredCancellation->cancel();
        }
    }

    private function insertTimeout(): void
    {
        self::getTimeoutQueue()->insert(
            $this->client,
            0,
            static fn (Client $client) => $client->close(),
            $this->connectionTimeout,
        );
    }

    private function updateTimeout(): void
    {
        self::getTimeoutQueue()->update($this->client, 0, $this->connectionTimeout);
    }

    private function suspendTimeout(): void
    {
        self::getTimeoutQueue()->suspend($this->client, 0);
    }

    private function removeTimeout(): void
    {
        self::getTimeoutQueue()->remove($this->client, 0);
    }

    /**
     * Selects HTTP/2 or HTTP/1.x writer depending on connection status.
     */
    protected function write(Request $request, Response $response): void
    {
        if ($this->http2driver) {
            $this->http2driver->write($request, $response);
            return;
        }

        $deferred = new DeferredFuture;
        $lastWrite = $this->lastWrite;
        $this->lastWrite = $future = $deferred->getFuture();

        try {
            $this->send($lastWrite, $response, $request);

            if ($response->isUpgraded()) {
                $this->upgrade($request, $response);
            }
        } finally {
            if ($this->lastWrite === $future) {
                $this->lastWrite = null;
            }
            $deferred->complete();
        }
    }

    /**
     * HTTP/1.x response writer.
     */
    private function send(?Future $lastWrite, Response $response, ?Request $request = null): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(isset($this->client), "The driver has not been setup; call setup first");

        $lastWrite?->await(); // Prevent sending multiple responses at once.

        $protocol = $request?->getProtocolVersion() ?? "1.0";

        $status = $response->getStatus();
        $reason = $response->getReason();

        [$headers, $need, $shouldClose] = $this->filter($response, $request, $protocol);

        $trailers = $response->getTrailers();

        if (($fields = $trailers?->getFields()) && !isset($headers["trailer"])) {
            $headers["trailer"] = [\implode(", ", $fields)];
        }

        $chunked = !$shouldClose
            && (!isset($headers["content-length"]) || $trailers !== null)
            && $protocol === "1.1"
            && $status >= HttpStatus::OK;

        if ($chunked) {
            $headers["transfer-encoding"] = ["chunked"];
        }

        $chunk = "HTTP/$protocol $status $reason\r\n" . Rfc7230::formatHeaders($headers) . "\r\n";

        $wrote = 0;

        try {
            $this->writableStream->write($chunk);

            $chunk = null; // Required for the finally, not directly overwritten, even if your IDE says otherwise.

            if ($request?->getMethod() === "HEAD") {
                if ($shouldClose) {
                    $this->writableStream->end();
                }
                $need = null;

                return;
            }

            $body = $response->getBody();

            $this->updateTimeout();

            $cancellation = $this->deferredCancellation->getCancellation();

            while (null !== $chunk = $body->read($cancellation)) {
                if ($chunk === "") {
                    continue;
                }
                $wrote += \strlen($chunk);

                if ($chunked) {
                    $chunk = \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
                }

                $this->writableStream->write($chunk);
                $this->updateTimeout();
            }

            if ($chunked) {
                $chunk = "0\r\n";

                if ($trailers !== null) {
                    $trailers = $trailers->await($cancellation);
                    $chunk .= Rfc7230::formatHeaders($trailers->getHeaders());
                }

                $chunk .= "\r\n";

                $this->writableStream->write($chunk);
                $chunk = null;
            }

            if ($shouldClose) {
                $this->writableStream->end();
            }
        } catch (StreamException|ClientException|CancelledException) {
            return; // Client will be closed in finally.
        } finally {
            /** @psalm-suppress TypeDoesNotContainType */
            if ($chunk !== null || ($need !== null && $wrote !== $need)) {
                $this->client->close();
            }
        }
    }

    /**
     * Filters and updates response headers based on protocol and connection header from the request.
     *
     * @param string $protocol Request protocol.
     *
     * @return array{array<non-empty-string, list<string>>, int|null,  bool} Response headers to be written,
     *      content length, and if the connection should be closed.
     */
    private function filter(Response $response, ?Request $request, string $protocol = "1.0"): array
    {
        static $closeReduce, $keepAliveReduce;
        $closeReduce ??= self::makeHeaderReduceClosure("close");
        $keepAliveReduce ??= self::makeHeaderReduceClosure("keep-alive");

        $headers = $response->getHeaders();

        if ($response->getStatus() < HttpStatus::OK) {
            unset($headers['content-length']); // 1xx responses do not have a body.
            return [$headers, null, false];
        }

        foreach ($response->getPushes() as $push) {
            $headers["link"][] = "<{$push->getUri()}>; rel=preload";
        }

        $requestConnectionHeaders = $request?->getHeaderArray("connection") ?? [];
        $responseConnectionHeaders = $headers["connection"] ?? [];

        $contentLength = $headers["content-length"][0] ?? null;
        if ($contentLength !== null) {
            $contentLength = (int) $contentLength;
        }

        $shouldClose = $request === null
            || \array_reduce($requestConnectionHeaders, $closeReduce, false)
            || \array_reduce($responseConnectionHeaders, $closeReduce, false)
            || $protocol === "1.0" && !\array_reduce($requestConnectionHeaders, $keepAliveReduce, false);

        if ($contentLength !== null) {
            unset($headers["transfer-encoding"]);
        } elseif ($protocol === "1.1") {
            unset($headers["content-length"]);
        } else {
            $shouldClose = true;
        }

        if ($shouldClose) {
            $headers["connection"] = ["close"];
        } else {
            $headers["connection"] = ["keep-alive"];
            $headers["keep-alive"] = ["timeout=" . $this->connectionTimeout];
        }

        $headers["date"] = [formatDateHeader()];

        return [$headers, $contentLength, $shouldClose];
    }

    /**
     * Invokes the upgrade handler of the Response with the socket upgraded from the HTTP server.
     */
    private function upgrade(Request $request, Response $response): void
    {
        $upgradeHandler = $response->getUpgradeHandler();
        if (!$upgradeHandler) {
            throw new \Error('Response was not upgraded');
        }

        $this->continue = false;

        $client = $request->getClient();

        $this->removeTimeout();

        $stream = $this->currentBuffer === ''
            ? $this->readableStream
            : new ReadableStreamChain(new ReadableBuffer($this->currentBuffer), $this->readableStream);

        $socket = new UpgradedSocket($client, $stream, $this->writableStream);

        try {
            $upgradeHandler($socket, $request, $response);
        } catch (\Throwable $exception) {
            $exceptionClass = $exception::class;

            $this->logger->error(
                "Unexpected {$exceptionClass} thrown during socket upgrade, closing connection.",
                ['exception' => $exception]
            );

            $client->close();
        }
    }

    /**
     * Creates a service unavailable response from the error handler and sends that response to the client.
     *
     * @return Future<void>
     */
    private function sendServiceUnavailableResponse(?Request $request): Future
    {
        $response = $this->errorHandler->handleError(HttpStatus::SERVICE_UNAVAILABLE, request: $request);
        $response->setHeader("connection", "close");

        return $this->lastWrite = async($this->send(...), $this->lastWrite, $response);
    }

    /**
     * Creates an error response from the error handler and sends that response to the client.
     *
     * @return Future<void>
     */
    private function sendErrorResponse(ClientException $exception, ?Request $request): Future
    {
        $message = $exception->getMessage();
        $status = $exception->getCode();

        if (!(HttpStatus::isClientError($status) || HttpStatus::isServerError($status))) {
            $status = HttpStatus::BAD_REQUEST;
        }

        $response = $this->errorHandler->handleError($status, $message, $request);
        $response->setHeader("connection", "close");

        return $this->lastWrite = async($this->send(...), $this->lastWrite, $response);
    }

    public function getPendingRequestCount(): int
    {
        if ($this->bodyQueue) {
            return 1;
        }

        if ($this->http2driver) {
            return $this->http2driver->getPendingRequestCount();
        }

        return 0;
    }

    public function stop(): void
    {
        $this->stopping = true;

        $this->pendingResponse->await();
        $this->lastWrite?->await();
        $this->deferredCancellation->cancel();
    }

    public function getApplicationLayerProtocols(): array
    {
        return ['http/1.1'];
    }
}
