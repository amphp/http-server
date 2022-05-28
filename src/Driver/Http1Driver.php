<?php

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamChain;
use Amp\ByteStream\WritableStream;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Rfc7230;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Internal\AbstractHttpDriver;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Amp\Socket\InternetAddress;
use League\Uri;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\async;
use function Amp\Http\formatDateHeader;

final class Http1Driver extends AbstractHttpDriver
{
    private ?Http2Driver $http2driver = null;

    private Client $client;

    private ReadableStream $readableStream;
    private WritableStream $writableStream;

    private int $pendingResponseCount = 0;

    private ?Queue $bodyQueue = null;

    private Future $pendingResponse;

    private Future $lastWrite;

    private bool $stopping = false;

    private string $currentBuffer = "";

    private bool $continue = true;

    public function __construct(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        private readonly int $connectionTimeout = self::DEFAULT_STREAM_TIMEOUT,
        private readonly int $headerSizeLimit = self::DEFAULT_HEADER_SIZE_LIMIT,
        private readonly int $bodySizeLimit = self::DEFAULT_BODY_SIZE_LIMIT,
        array $allowedMethods = self::DEFAULT_ALLOWED_METHODS,
        private readonly bool $allowHttp2Upgrade = false,
    ) {
        parent::__construct($requestHandler, $errorHandler, $logger, $allowedMethods);

        $this->lastWrite = Future::complete();
        $this->pendingResponse = Future::complete();
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

        $buffer = $readableStream->read();
        if ($buffer === null) {
            $this->removeTimeout();
            return;
        }

        try {
            do {
                if ($this->http2driver) {
                    $this->removeTimeout();
                    $this->http2driver->handleClientWithBuffer($buffer, $this->readableStream);
                    return;
                }

                $contentLength = null;
                $isChunked = false;
                $rawHeaders = ""; // For Psalm

                do {
                    if ($this->stopping) {
                        return;
                    }

                    $buffer = \ltrim($buffer, "\r\n");

                    if ($headerPos = \strpos($buffer, "\r\n\r\n")) {
                        $rawHeaders = \substr($buffer, 0, $headerPos + 2);
                        $buffer = \substr($buffer, $headerPos + 4);
                        break;
                    }

                    if (\strlen($buffer) > $headerSizeLimit) {
                        throw new ClientException(
                            $this->client,
                            "Bad Request: header size violation",
                            Status::REQUEST_HEADER_FIELDS_TOO_LARGE
                        );
                    }

                    $chunk = $readableStream->read();
                    if ($chunk === null) {
                        return;
                    }

                    $buffer .= $chunk;
                } while (true);

                $startLineEndPos = (int) \strpos($rawHeaders, "\r\n");
                $startLine = \substr($rawHeaders, 0, $startLineEndPos);
                $rawHeaders = \substr($rawHeaders, $startLineEndPos + 2);

                if (!\preg_match("/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)$/i", $startLine, $matches)) {
                    throw new ClientException($this->client, "Bad Request: invalid request line", Status::BAD_REQUEST);
                }

                [, $method, $target, $protocol] = $matches;

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
                            allowedMethods: $this->allowedMethods,
                            pushEnabled: false,
                        );

                        $this->http2driver->handleClient(
                            $this->client,
                            new ReadableStreamChain(
                                new ReadableBuffer("$startLine\r\n$rawHeaders\r\n$buffer"),
                                $readableStream
                            ),
                            $writableStream
                        );

                        return;
                    }

                    throw new ClientException(
                        $this->client,
                        "Unsupported version $protocol",
                        Status::HTTP_VERSION_NOT_SUPPORTED
                    );
                }

                if (!$rawHeaders) {
                    throw new ClientException($this->client, "Bad Request: missing host header", Status::BAD_REQUEST);
                }

                try {
                    if ($protocol === "1.0") {
                        // Header folding is deprecated for HTTP/1.1, but allowed in HTTP/1.0
                        $rawHeaders = \preg_replace(Rfc7230::HEADER_FOLD_REGEX, ' ', $rawHeaders);
                    }

                    $parsedHeaders = Rfc7230::parseRawHeaders($rawHeaders);
                    $headers = [];
                    foreach ($parsedHeaders as [$key, $transferEncoding]) {
                        $headers[\strtolower($key)][] = $transferEncoding;
                    }
                } catch (InvalidHeaderException $e) {
                    throw new ClientException(
                        $this->client,
                        "Bad Request: " . $e->getMessage(),
                        Status::BAD_REQUEST
                    );
                }

                if (isset($contentLength["content-length"][1])) {
                    throw new ClientException(
                        $this->client,
                        "Bad Request: multiple content-length headers",
                        Status::BAD_REQUEST
                    );
                }

                $contentLength = $headers["content-length"][0] ?? null;
                if ($contentLength !== null) {
                    if (!\preg_match("/^(?:0|[1-9]\\d*)$/", $contentLength)) {
                        throw new ClientException(
                            $this->client,
                            "Bad Request: invalid content length",
                            Status::BAD_REQUEST
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
                            "Bad Request: unsupported transfer-encoding",
                            Status::BAD_REQUEST
                        ),
                    };
                }

                if (!isset($headers["host"][0])) {
                    throw new ClientException($this->client, "Bad Request: missing host header", Status::BAD_REQUEST);
                }

                if (isset($headers["host"][1])) {
                    throw new ClientException($this->client, "Bad Request: multiple host headers", Status::BAD_REQUEST);
                }

                if (!\preg_match("#^([A-Z\d.\-]+|\[[\d:]+])(?::([1-9]\d*))?$#i", $headers["host"][0], $matches)) {
                    throw new ClientException($this->client, "Bad Request: invalid host header", Status::BAD_REQUEST);
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

                        $uri = Uri\Http::createFromComponents([
                            "scheme" => $scheme,
                            "host" => $host,
                            "port" => $port,
                            "path" => $target,
                            "query" => $query,
                        ]);
                    } elseif ($target === "*") { // asterisk-form
                        $uri = Uri\Http::createFromComponents([
                            "scheme" => $scheme,
                            "host" => $host,
                            "port" => $port,
                        ]);
                    } elseif (\preg_match("#^https?://#i", $target)) { // absolute-form
                        $uri = Uri\Http::createFromString($target);

                        if ($uri->getHost() !== $host || $uri->getPort() !== $port) {
                            throw new ClientException(
                                $this->client,
                                "Bad Request: target host mis-matched to host header",
                                Status::BAD_REQUEST
                            );
                        }

                        if ($uri->getPath() === "") {
                            throw new ClientException(
                                $this->client,
                                "Bad Request: no request path provided in target",
                                Status::BAD_REQUEST
                            );
                        }
                    } else { // authority-form
                        if ($method !== "CONNECT") {
                            throw new ClientException(
                                $this->client,
                                "Bad Request: authority-form only valid for CONNECT requests",
                                Status::BAD_REQUEST
                            );
                        }

                        if (!\preg_match("#^([A-Z\d.\-]+|\[[\d:]+]):([1-9]\d*)$#i", $target, $matches)) {
                            throw new ClientException(
                                $this->client,
                                "Bad Request: invalid connect target",
                                Status::BAD_REQUEST
                            );
                        }

                        $uri = Uri\Http::createFromComponents([
                            "host" => $matches[1],
                            "port" => (int) $matches[2],
                        ]);
                    }
                } catch (Uri\Contracts\UriException $exception) {
                    throw new ClientException(
                        $this->client,
                        "Bad Request: invalid target",
                        Status::BAD_REQUEST,
                        $exception
                    );
                }

                if (isset($headers["expect"][0]) && \strtolower($headers["expect"][0]) === "100-continue") {
                    $this->write(
                        new Request($this->client, $method, $uri, $headers, '', $protocol),
                        new Response(Status::CONTINUE, [])
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
                        new Response(Status::SWITCHING_PROTOCOLS, [
                            "connection" => "upgrade",
                            "upgrade" => "h2c",
                        ])
                    );

                    // Internal upgrade
                    $this->http2driver = new Http2Driver(
                        requestHandler: $this->requestHandler,
                        errorHandler: $this->errorHandler,
                        logger: $this->logger,
                        streamTimeout: $this->connectionTimeout,
                        connectionTimeout: $this->connectionTimeout,
                        headerSizeLimit: $this->headerSizeLimit,
                        bodySizeLimit: $this->bodySizeLimit,
                        allowedMethods: $this->allowedMethods,
                        pushEnabled: false,
                        settings: $h2cSettings,
                    );

                    $this->http2driver->initializeWriting($this->client, $this->writableStream);

                    // Remove headers that are not related to the HTTP/2 request.
                    foreach ($parsedHeaders as $index => [$key, $transferEncoding]) {
                        switch (\strtolower($key)) {
                            case "upgrade":
                            case "connection":
                            case "http2-settings":
                                unset($parsedHeaders[$index]);
                                break;
                        }
                    }

                    $protocol = "2";
                }

                $this->updateTimeout();

                if (!($isChunked || $contentLength)) {
                    $request = new Request($this->client, $method, $uri, [], '', $protocol);
                    foreach ($parsedHeaders as [$key, $transferEncoding]) {
                        $request->addHeader($key, $transferEncoding);
                    }

                    $this->pendingResponseCount++;
                    $this->currentBuffer = $buffer;
                    $this->handleRequest($request);
                    $this->pendingResponseCount--;
                    $request = null; // DO NOT leave a reference to the Request object in the parser!

                    continue;
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
                        Status::BAD_REQUEST,
                        $exception
                    );
                }

                $request = new Request($this->client, $method, $uri, [], $body, $protocol, $trailers);
                foreach ($parsedHeaders as [$key, $transferEncoding]) {
                    $request->addHeader($key, $transferEncoding);
                }

                // Do not await future until body is completely read.
                $this->pendingResponseCount++;
                $this->currentBuffer = $buffer;
                $this->pendingResponse = async($this->handleRequest(...), $request);

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
                                    Status::BAD_REQUEST
                                );
                            }

                            $chunk = $this->readableStream->read();
                            if ($chunk === null) {
                                return;
                            }

                            $buffer .= $chunk;
                        }

                        $line = \substr($buffer, 0, $lineEndPos);
                        $buffer = \substr($buffer, $lineEndPos + 2);
                        $hex = \trim($line);
                        if ($hex !== "0") {
                            $hex = \ltrim($line, "0");

                            if (!\preg_match("/^[1-9A-F][0-9A-F]*$/i", $hex)) {
                                throw new ClientException(
                                    $this->client,
                                    "Bad Request: invalid hex chunk size",
                                    Status::BAD_REQUEST
                                );
                            }
                        }

                        $chunkLengthRemaining = \hexdec($hex);

                        if ($chunkLengthRemaining === 0) {
                            while (!isset($buffer[1])) {
                                $chunk = $readableStream->read();
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
                                        Status::BAD_REQUEST
                                    );
                                }

                                $chunk = $this->readableStream->read();
                                if ($chunk === null) {
                                    return;
                                }

                                $buffer .= $chunk;
                            } while (true);

                            if (isset($rawTrailers)) {
                                try {
                                    $trailers = Rfc7230::parseHeaders($rawTrailers);
                                } catch (InvalidHeaderException $e) {
                                    throw new ClientException(
                                        $this->client,
                                        "Bad Request: " . $e->getMessage(),
                                        Status::BAD_REQUEST
                                    );
                                }

                                if (\array_intersect_key($trailers, Trailers::DISALLOWED_TRAILERS)) {
                                    throw new ClientException(
                                        $this->client,
                                        "Trailer section contains disallowed headers",
                                        Status::BAD_REQUEST
                                    );
                                }

                                $trailerDeferred->complete($trailers);
                                $trailerDeferred = null;
                            }

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

                                    $body = $readableStream->read();
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
                                    Status::PAYLOAD_TOO_LARGE
                                );
                            } while ($bodySizeLimit < $bodySize + $chunkLengthRemaining);
                        }

                        while (true) {
                            $bufferLength = \strlen($buffer);

                            if (!$bufferLength) {
                                $chunk = $readableStream->read();
                                if ($chunk === null) {
                                    return;
                                }

                                $buffer = $chunk;
                                $bufferLength = \strlen($buffer);
                            }

                            // These first two (extreme) edge cases prevent errors where the packet boundary ends after
                            // the \r and before the \n at the end of a chunk.
                            if ($bufferLength === $chunkLengthRemaining || $bufferLength === $chunkLengthRemaining + 1) {
                                $chunk = $readableStream->read();
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

                            $chunk = $readableStream->read();
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
                        throw new ClientException($this->client, "Payload too large", Status::PAYLOAD_TOO_LARGE);
                    }
                }

                /** @psalm-suppress RedundantCondition */
                if ($trailerDeferred !== null) {
                    $trailerDeferred->complete([]);
                    $trailerDeferred = null;
                }

                $this->bodyQueue = null;
                $queue->complete();

                $this->updateTimeout();

                $this->pendingResponse->await(); // Wait for response to be generated.
                $this->pendingResponseCount--;
            } while ($this->continue);
        } catch (ClientException $exception) {
            if ($this->bodyQueue === null || !$this->pendingResponseCount) {
                // Send an error response only if another response has not already been sent to the request.
                $this->sendErrorResponse($exception)->await();
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

            if ($this->bodyQueue !== null) {
                /** @psalm-suppress TypeDoesNotContainNull */
                $exception ??= new ClientException(
                    $this->client,
                    "Client disconnected",
                    Status::REQUEST_TIMEOUT
                );

                $this->bodyQueue->error($exception);
                $this->bodyQueue = null;
            }

            /** @psalm-suppress TypeDoesNotContainType */
            if (isset($trailerDeferred)) {
                /** @psalm-suppress TypeDoesNotContainNull */
                $exception ??= new ClientException(
                    $this->client,
                    "Client disconnected",
                    Status::REQUEST_TIMEOUT
                );

                $trailerDeferred->error($exception);
                $trailerDeferred = null;
            }
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
        $this->lastWrite = $deferred->getFuture();

        try {
            $this->send($lastWrite, $response, $request);

            if ($response->isUpgraded()) {
                $this->upgrade($request, $response);
            }
        } finally {
            $deferred->complete();
        }
    }

    /**
     * HTTP/1.x response writer.
     */
    private function send(Future $lastWrite, Response $response, ?Request $request = null): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(isset($this->client), "The driver has not been setup; call setup first");

        $lastWrite->await(); // Prevent sending multiple responses at once.

        $shouldClose = false;

        $protocol = $request !== null ? $request->getProtocolVersion() : "1.0";

        $status = $response->getStatus();
        $reason = $response->getReason();

        $headers = $this->filter($response, $protocol, $request ? $request->getHeaderArray("connection") : []);

        $trailers = $response->getTrailers();

        if ($trailers !== null && !isset($headers["trailer"]) && ($fields = $trailers->getFields())) {
            $headers["trailer"] = [\implode(", ", $fields)];
        }

        $chunked = (!isset($headers["content-length"]) || $trailers !== null)
            && $protocol === "1.1"
            && $status >= Status::OK;

        if (!empty($headers["connection"])) {
            foreach ($headers["connection"] as $connection) {
                if (\strcasecmp($connection, "close") === 0) {
                    $chunked = false;
                    $shouldClose = true;
                }
            }
        }

        if ($chunked) {
            $headers["transfer-encoding"] = ["chunked"];
        }

        $chunk = "HTTP/$protocol $status $reason\r\n";
        $chunk .= Rfc7230::formatHeaders($headers);
        $chunk .= "\r\n";

        $this->writableStream->write($chunk);

        if ($request !== null && $request->getMethod() === "HEAD") {
            if ($shouldClose) {
                $this->writableStream->end();
            }

            return;
        }

        $chunk = null; // Required for the finally, not directly overwritten, even if your IDE says otherwise.
        $body = $response->getBody();

        $this->updateTimeout();

        try {
            while (null !== $chunk = $body->read()) {
                if ($chunk === "") {
                    continue;
                }

                if ($chunked) {
                    $chunk = \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
                }

                $this->writableStream->write($chunk);
                $this->updateTimeout();
            }

            if ($chunked) {
                $chunk = "0\r\n";

                if ($trailers !== null) {
                    $trailers = $trailers->await();
                    $chunk .= Rfc7230::formatHeaders($trailers->getHeaders());
                }

                $chunk .= "\r\n";

                $this->writableStream->write($chunk);
                $chunk = null;
            }

            if ($shouldClose) {
                $this->writableStream->end();
            }
        } catch (ClientException) {
            return; // Client will be closed in finally.
        } finally {
            /** @psalm-suppress TypeDoesNotContainType */
            if ($chunk !== null) {
                $this->client->close();
            }
        }
    }

    /**
     * Filters and updates response headers based on protocol and connection header from the request.
     *
     * @param string $protocol Request protocol.
     * @param array $connection Request connection header.
     *
     * @return string[][] Response headers to be written.
     */
    private function filter(Response $response, string $protocol = "1.0", array $connection = []): array
    {
        $headers = $response->getHeaders();

        if ($response->getStatus() < Status::OK) {
            unset($headers['content-length']); // 1xx responses do not have a body.
            return $headers;
        }

        foreach ($response->getPushes() as $push) {
            $headers["link"][] = "<{$push->getUri()}>; rel=preload";
        }

        $contentLength = $headers["content-length"][0] ?? null;
        $shouldClose = \in_array("close", $connection, true)
            || (isset($headers["connection"]) && \in_array("close", $headers["connection"], true));

        if ($contentLength !== null) {
            $shouldClose = $shouldClose || $protocol === "1.0";
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

        return $headers;
    }

    private function updateTimeout(): void
    {
        self::getTimeoutQueue()->update($this->client, 0, $this->connectionTimeout);
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
     * Creates an error response from the error handler and sends that response to the client.
     */
    private function sendErrorResponse(ClientException $exception): Future
    {
        $message = $exception->getMessage();
        $status = $exception->getCode() ?: Status::BAD_REQUEST;

        \assert($status >= 400 && $status < 500);

        $response = $this->errorHandler->handleError($status, $message);
        $response->setHeader("connection", "close");

        $lastWrite = $this->lastWrite;
        return $this->lastWrite = async(fn () => $this->send($lastWrite, $response));
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
        $this->lastWrite->await();
    }

    public function getApplicationLayerProtocols(): array
    {
        return ['http/1.1'];
    }
}
