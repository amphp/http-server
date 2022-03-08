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
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use League\Uri;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Http\formatDateHeader;

final class Http1Driver extends AbstractHttpDriver
{
    private static array $clients = [];

    private static TimeoutCache $timeoutCache;
    private static string $timeoutId;

    private static function getTimeoutCache(): TimeoutCache
    {
        if (!isset(self::$timeoutCache)) {
            self::$timeoutId = EventLoop::disable(EventLoop::repeat(1, self::checkTimeouts(...)));
        }

        return self::$timeoutCache ??= new TimeoutCache;
    }

    private static function checkTimeouts(): void
    {
        $now = \time();

        while ($id = self::$timeoutCache->extract($now)) {
            \assert(isset(self::$clients[$id]), "Timeout cache contains an invalid client ID");

            $client = self::$clients[$id];

            if ($client->isWaitingOnResponse()) {
                self::$timeoutCache->update($id, $now + 1);
                continue;
            }

            // Client is either idle or taking too long to send request, so simply close the connection.
            $client->close();
        }
    }

    private static function addClient(Client $client): void
    {
        self::getTimeoutCache(); // init timeoutId if necessary

        if (\count(self::$clients) === 0) {
            EventLoop::enable(self::$timeoutId);
        }

        self::$clients[$client->getId()] = $client;

        $client->onClose(static function (Client $client) {
            self::$timeoutCache->clear($client->getId());

            unset(self::$clients[$client->getId()]);

            if (\count(self::$clients)) {
                EventLoop::disable(self::$timeoutId);
            }
        });
    }

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

    public function __construct(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        private PsrLogger $logger,
        Options $options
    ) {
        parent::__construct($requestHandler, $errorHandler, $logger, $options);

        $this->lastWrite = Future::complete();
        $this->pendingResponse = Future::complete();
    }

    public function handleClient(Client $client, ReadableStream $readableStream, WritableStream $writableStream): void
    {
        \assert(!isset($this->client));

        self::addClient($client);

        $this->client = $client;
        $this->readableStream = $readableStream;
        $this->writableStream = $writableStream;

        $headerSizeLimit = $this->getOptions()->getHeaderSizeLimit();
        $this->updateTimeout();

        $buffer = $readableStream->read();
        if ($buffer === null) {
            return;
        }

        try {
            do {
                if ($this->http2driver) {
                    $this->http2driver->handleClientWithBuffer($buffer, $this->readableStream);
                    return;
                }

                $contentLength = null;
                $isChunked = false;

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

                $startLineEndPos = \strpos($rawHeaders, "\r\n");
                $startLine = \substr($rawHeaders, 0, $startLineEndPos);
                $rawHeaders = \substr($rawHeaders, $startLineEndPos + 2);

                if (!\preg_match("/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)$/i", $startLine, $matches)) {
                    throw new ClientException($this->client, "Bad Request: invalid request line", Status::BAD_REQUEST);
                }

                [, $method, $target, $protocol] = $matches;

                if ($protocol !== "1.1" && $protocol !== "1.0") {
                    if ($protocol === "2.0" && $this->getOptions()->isHttp2UpgradeAllowed()) {
                        // Internal upgrade to HTTP/2.
                        $this->http2driver = new Http2Driver(
                            $this->getRequestHandler(),
                            $this->getErrorHandler(),
                            $this->getLogger(),
                            $this->getOptions(),
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
                    foreach ($parsedHeaders as [$key, $value]) {
                        $headers[\strtolower($key)][] = $value;
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
                    $value = \strtolower(\implode(', ', $headers["transfer-encoding"]));
                    if (!($isChunked = $value === "chunked") && $value !== "identity") {
                        throw new ClientException(
                            $this->client,
                            "Bad Request: unsupported transfer-encoding",
                            Status::BAD_REQUEST
                        );
                    }
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

                $host = $matches[1];
                $port = isset($matches[2]) ? (int) $matches[2] : $this->client->getLocalAddress()->getPort();
                $scheme = $this->client->isEncrypted() ? "https" : "http";
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
                    && !$this->client->isEncrypted()
                    && $this->getOptions()->isHttp2UpgradeAllowed()
                    && false !== \stripos($headers["connection"][0], "upgrade")
                    && \strtolower($headers["upgrade"][0]) === "h2c"
                    && false !== $h2cSettings = \base64_decode(\strtr($headers["http2-settings"][0], "-_", "+/"),
                        true)
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
                        $this->getRequestHandler(),
                        $this->getErrorHandler(),
                        $this->getLogger(),
                        $this->getOptions(),
                        $h2cSettings
                    );

                    $this->http2driver->initializeWriting($this->client, $this->writableStream);

                    // Remove headers that are not related to the HTTP/2 request.
                    foreach ($parsedHeaders as $index => [$key, $value]) {
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
                    foreach ($parsedHeaders as [$key, $value]) {
                        $request->addHeader($key, $value);
                    }

                    $this->pendingResponseCount++;
                    $this->currentBuffer = $buffer;
                    $this->pendingResponse = async($this->handleRequest(...), $request);
                    $request = null; // DO NOT leave a reference to the Request object in the parser!
                    $this->pendingResponse->await(); // Wait for response to be generated.
                    $this->pendingResponseCount--;

                    continue;
                }

                // HTTP/1.x clients only ever have a single body emitter.
                $this->bodyQueue = $queue = new Queue();
                $trailerDeferred = new DeferredFuture;
                $bodySizeLimit = $this->getOptions()->getBodySizeLimit();

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
                        0,
                        $exception
                    );
                }

                $request = new Request($this->client, $method, $uri, [], $body, $protocol, $trailers);
                foreach ($parsedHeaders as [$key, $value]) {
                    $request->addHeader($key, $value);
                }

                // Do not await future until body is completely read.
                $this->pendingResponseCount++;
                $this->currentBuffer = $buffer;
                $this->pendingResponse = async($this->handleRequest(...), $request, $buffer);

                // DO NOT leave a reference to the Request, Trailers, or Body objects within the parser!
                $request = null;
                $trailers = null;
                $body = "";

                $bodySize = 0;

                if ($isChunked) {
                    while (true) {
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

                            if ($rawTrailers) {
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
                                        $body = "";
                                        $bodySize += $bodyBufferSize;
                                        $remaining -= $bodyBufferSize;
                                        $bodyBufferSize = \strlen($body);
                                    }

                                    if (!$bodyBufferSize) {
                                        $chunk = $readableStream->read();
                                        if ($chunk === null) {
                                            return;
                                        }

                                        $body = $chunk;
                                        $bodyBufferSize = \strlen($body);
                                    }
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

                                throw new ClientException($this->client, "Payload too large",
                                    Status::PAYLOAD_TOO_LARGE);
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

                if ($trailerDeferred !== null) {
                    $trailerDeferred->complete([]);
                    $trailerDeferred = null;
                }

                $this->bodyQueue = null;
                $queue->complete();

                $this->updateTimeout();

                $this->pendingResponse->await(); // Wait for response to be generated.
                $this->pendingResponseCount--;
            } while (true);
        } catch (ClientException $exception) {
            if ($this->bodyQueue === null || $this->pendingResponseCount) {
                // Send an error response only if another response has not already been sent to the request.
                $this->sendErrorResponse($exception)->await();
            }
            return;
        } finally {
            if ($this->bodyQueue !== null) {
                $queue = $this->bodyQueue;
                $this->bodyQueue = null;
                $queue->error($exception ?? new ClientException(
                        $this->client,
                        "Client disconnected",
                        Status::REQUEST_TIMEOUT
                    ));
            }

            if (isset($trailerDeferred)) {
                $trailerDeferred->error($exception ?? new ClientException(
                        $this->client,
                        "Client disconnected",
                        Status::REQUEST_TIMEOUT
                    ));
                $trailerDeferred = null;
            }
        }
    }

    /**
     * Selects HTTP/2 or HTTP/1.x writer depending on connection status.
     */
    protected function write(Request $request, Response $response): void
    {
        if ($this->http2driver) {
            $this->http2driver->write($request, $response);
        } else {
            $deferred = new DeferredFuture;
            $lastWrite = $this->lastWrite;
            $this->lastWrite = $deferred->getFuture();

            try {
                $this->send($lastWrite, $response, $request);
            } finally {
                $deferred->complete();
            }

            if ($response->isUpgraded()) {
                $this->upgrade($request, $response);
            }
        }
    }

    /**
     * Invokes the upgrade handler of the Response with the socket upgraded from the HTTP server.
     */
    private function upgrade(
        Request $request,
        Response $response,
    ): void {
        $socket = new UpgradedSocket(
            $request->getClient(),
            new ReadableStreamChain(new ReadableBuffer($this->currentBuffer), $this->readableStream),
            $this->writableStream,
        );

        try {
            ($response->getUpgradeHandler())($socket, $request, $response);
        } catch (\Throwable $exception) {
            $exceptionClass = $exception::class;

            $this->logger->error(
                "Unexpected {$exceptionClass} thrown during socket upgrade, closing connection.",
                ['exception' => $exception]
            );

            $client->close();
        }
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

    private function updateTimeout(): void
    {
        self::getTimeoutCache()->update($this->client->getId(), \time() + $this->getOptions()->getHttp1Timeout());
    }

    /**
     * HTTP/1.x response writer.
     */
    private function send(Future $lastWrite, Response $response, ?Request $request = null): void
    {
        \assert(isset($this->client), "The driver has not been setup; call setup first");

        $lastWrite->await(); // Prevent sending multiple responses at once.

        $this->updateTimeout();

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

        $buffer = "HTTP/$protocol $status $reason\r\n";
        $buffer .= Rfc7230::formatHeaders($headers);
        $buffer .= "\r\n";

        if ($request !== null && $request->getMethod() === "HEAD") {
            $this->writableStream->write($buffer);

            if ($shouldClose) {
                $this->writableStream->end();
            }

            return;
        }

        $chunk = ""; // Required for the finally, not directly overwritten, even if your IDE says otherwise.
        $body = $response->getBody();
        $streamThreshold = $this->getOptions()->getStreamThreshold();

        try {
            while (null !== $chunk = $body->read()) {
                $length = \strlen($chunk);

                if ($length === 0) {
                    continue;
                }

                if ($chunked) {
                    $chunk = \sprintf("%x\r\n%s\r\n", $length, $chunk);
                }

                $buffer .= $chunk;

                $this->updateTimeout();

                if (\strlen($buffer) < $streamThreshold) {
                    continue;
                }

                $this->writableStream->write($buffer);
                $buffer = "";
            }

            if ($chunked) {
                $buffer .= "0\r\n";

                if ($trailers !== null) {
                    $trailers = $trailers->await();
                    $buffer .= Rfc7230::formatHeaders($trailers->getHeaders());
                }

                $buffer .= "\r\n";
            }

            if ($buffer !== "" || $shouldClose) {
                $this->updateTimeout();
                $this->writableStream->write($buffer);

                if ($shouldClose) {
                    $this->writableStream->end();
                }
            }
        } catch (ClientException) {
            return; // Client will be closed in finally.
        } finally {
            if ($chunk !== null) {
                $this->client->close();
            }
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

        $response = $this->getErrorHandler()->handleError($status, $message);
        $response->setHeader("connection", "close");

        $lastWrite = $this->lastWrite;
        return $this->lastWrite = async(fn () => $this->send($lastWrite, $response));
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
            $headers["keep-alive"] = ["timeout=" . $this->getOptions()->getHttp1Timeout()];
        }

        $headers["date"] = [formatDateHeader()];

        return $headers;
    }

    public function getApplicationLayerProtocols(): array
    {
        return ['http/1.1'];
    }
}
