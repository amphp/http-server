<?php

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\IteratorStream;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Message;
use Amp\Http\Rfc7230;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\Promise;
use Amp\TimeoutException;
use League\Uri;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;
use function Amp\Http\formatDateHeader;

final class Http1Driver implements HttpDriver
{
    /** @var Http2Driver|null */
    private $http2;

    /** @var Client */
    private $client;

    /** @var Options */
    private $options;

    /** @var PsrLogger */
    private $logger;

    /** @var Emitter|null */
    private $bodyEmitter;

    /** @var Promise|null */
    private $pendingResponse;

    /** @var callable */
    private $onMessage;

    /** @var callable */
    private $write;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var Promise|null */
    private $lastWrite;

    /** @var bool */
    private $stopping = false;

    public function __construct(Options $options, ErrorHandler $errorHandler, PsrLogger $logger)
    {
        $this->options = $options;
        $this->errorHandler = $errorHandler;
        $this->logger = $logger;
    }

    /** {@inheritdoc} */
    public function setup(Client $client, callable $onMessage, callable $write): \Generator
    {
        \assert(!$this->client, "The driver has already been setup");

        $this->client = $client;
        $this->onMessage = $onMessage;
        $this->write = $write;

        return $this->parser();
    }

    /**
     * {@inheritdoc}
     *
     * Selects HTTP/2 or HTTP/1.x writer depending on connection status.
     */
    public function write(Request $request, Response $response): Promise
    {
        if ($this->http2) {
            return $this->http2->write($request, $response);
        }

        return $this->lastWrite = new Coroutine($this->send($response, $request));
    }

    public function getPendingRequestCount(): int
    {
        if ($this->bodyEmitter) {
            return 1;
        }

        if ($this->http2) {
            return $this->http2->getPendingRequestCount();
        }

        return 0;
    }

    public function stop(): Promise
    {
        $this->stopping = true;

        return call(function () {
            if ($this->pendingResponse) {
                yield $this->pendingResponse;
            }

            if ($this->lastWrite) {
                yield $this->lastWrite;
            }
        });
    }

    private function updateTimeout(): void
    {
        $this->client->updateExpirationTime(\time() + $this->options->getHttp1Timeout());
    }

    /**
     * HTTP/1.x response writer.
     *
     * @param \Amp\Http\Server\Response     $response
     * @param \Amp\Http\Server\Request|null $request
     *
     * @return \Generator
     */
    private function send(Response $response, Request $request = null): \Generator
    {
        \assert($this->client, "The driver has not been setup; call setup first");

        if ($this->lastWrite) {
            yield $this->lastWrite; // Prevent writing another response until the first is finished.
        }

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

        $buffer = "HTTP/{$protocol} {$status} {$reason}\r\n";
        $buffer .= Rfc7230::formatHeaders($headers);
        $buffer .= "\r\n";

        if ($request !== null && $request->getMethod() === "HEAD") {
            yield ($this->write)($buffer, $shouldClose);
            return;
        }

        $chunk = ""; // Required for the finally, not directly overwritten, even if your IDE says otherwise.
        $body = $response->getBody();
        $streamThreshold = $this->options->getStreamThreshold();

        try {
            $readPromise = $body->read();

            while (true) {
                try {
                    if ($buffer !== "") {
                        $chunk = yield Promise\timeout($readPromise, 100);
                    } else {
                        $chunk = yield $readPromise;
                    }

                    if ($chunk === null) {
                        break;
                    }

                    $readPromise = $body->read(); // directly start new read
                } catch (TimeoutException $e) {
                    goto flush;
                }

                $length = \strlen($chunk);

                if ($length === 0) {
                    continue;
                }

                if ($chunked) {
                    $chunk = \sprintf("%x\r\n%s\r\n", $length, $chunk);
                }

                $buffer .= $chunk;

                if (\strlen($buffer) < $streamThreshold) {
                    continue;
                }

                flush:

                // Initially the buffer won't be empty and contains the headers.
                // We save a separate write or the headers here.
                $promise = ($this->write)($buffer);

                $buffer = $chunk = ""; // Don't use null here, because of the finally

                $this->updateTimeout();

                yield $promise;
            }

            if ($chunked) {
                $buffer .= "0\r\n";

                if ($trailers !== null) {
                    $trailers = yield $trailers->await();
                    \assert($trailers instanceof Message);
                    $buffer .= Rfc7230::formatHeaders($trailers->getHeaders());
                }

                $buffer .= "\r\n";
            }

            if ($buffer !== "" || $shouldClose) {
                $this->updateTimeout();
                yield ($this->write)($buffer, $shouldClose);
            }
        } catch (ClientException $exception) {
            return; // Client will be closed in finally.
        } finally {
            if ($chunk !== null) {
                $this->client->close();
            }
        }
    }

    private function parser(): \Generator
    {
        $maxHeaderSize = $this->options->getHeaderSizeLimit();
        $parser = null;

        $this->updateTimeout();

        $buffer = yield;

        try {
            do {
                if ($parser !== null) { // May be set from upgrade request or receive of PRI * HTTP/2.0 request.
                    /** @var \Generator $parser */
                    yield from $parser; // Yield from HTTP/2 parser for duration of connection.
                    return;
                }

                $contentLength = null;
                $isChunked = false;

                do {
                    if ($this->stopping) {
                        // Yielding an unresolved promise prevents further data from being read.
                        yield (new Deferred)->promise();
                    }

                    $buffer = \ltrim($buffer, "\r\n");

                    if ($headerPos = \strpos($buffer, "\r\n\r\n")) {
                        $rawHeaders = \substr($buffer, 0, $headerPos + 2);
                        $buffer = (string) \substr($buffer, $headerPos + 4);
                        break;
                    }

                    if (\strlen($buffer) > $maxHeaderSize) {
                        throw new ClientException(
                            $this->client,
                            "Bad Request: header size violation",
                            Status::REQUEST_HEADER_FIELDS_TOO_LARGE
                        );
                    }

                    $buffer .= yield;
                } while (true);

                $startLineEndPos = \strpos($rawHeaders, "\r\n");
                $startLine = \substr($rawHeaders, 0, $startLineEndPos);
                $rawHeaders = \substr($rawHeaders, $startLineEndPos + 2);

                if (!\preg_match("/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)$/i", $startLine, $matches)) {
                    throw new ClientException($this->client, "Bad Request: invalid request line", Status::BAD_REQUEST);
                }

                [, $method, $target, $protocol] = $matches;

                if ($protocol !== "1.1" && $protocol !== "1.0") {
                    if ($protocol === "2.0" && $this->options->isHttp2UpgradeAllowed()) {
                        // Internal upgrade to HTTP/2.
                        $this->http2 = new Http2Driver($this->options, $this->logger);
                        $parser = $this->http2->setup($this->client, $this->onMessage, $this->write);

                        $parser->send("$startLine\r\n$rawHeaders\r\n$buffer");
                        continue; // Yield from the above parser immediately.
                    }

                    throw new ClientException(
                        $this->client,
                        "Unsupported version {$protocol}",
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

                    $headers = Rfc7230::parseRawHeaders($rawHeaders);
                    $headerMap = [];
                    foreach ($headers as [$key, $value]) {
                        $headerMap[\strtolower($key)][] = $value;
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

                $contentLength = $headerMap["content-length"][0] ?? null;
                if ($contentLength !== null) {
                    if (!\preg_match("/^(?:0|[1-9][0-9]*)$/", $contentLength)) {
                        throw new ClientException(
                            $this->client,
                            "Bad Request: invalid content length",
                            Status::BAD_REQUEST
                        );
                    }

                    $contentLength = (int) $contentLength;
                }

                if (isset($headerMap["transfer-encoding"])) {
                    $value = \strtolower(\implode(', ', $headerMap["transfer-encoding"]));
                    if (!($isChunked = $value === "chunked") && $value !== "identity") {
                        throw new ClientException(
                            $this->client,
                            "Bad Request: unsupported transfer-encoding",
                            Status::BAD_REQUEST
                        );
                    }
                }

                if (!isset($headerMap["host"][0])) {
                    throw new ClientException($this->client, "Bad Request: missing host header", Status::BAD_REQUEST);
                }

                if (isset($headerMap["host"][1])) {
                    throw new ClientException($this->client, "Bad Request: multiple host headers", Status::BAD_REQUEST);
                }

                if (!\preg_match("#^([A-Z\d\.\-]+|\[[\d:]+\])(?::([1-9]\d*))?$#i", $headerMap["host"][0], $matches)) {
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

                        if (!\preg_match("#^([A-Z\d\.\-]+|\[[\d:]+\]):([1-9]\d*)$#i", $target, $matches)) {
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

                if (isset($headerMap["expect"][0]) && \strtolower($headerMap["expect"][0]) === "100-continue") {
                    yield $this->write(
                        new Request($this->client, $method, $uri, $headerMap, null, $protocol),
                        new Response(Status::CONTINUE, [])
                    );
                }

                // Handle HTTP/2 upgrade request.
                if ($protocol === "1.1"
                    && isset($headerMap["upgrade"][0], $headerMap["http2-settings"][0], $headerMap["connection"][0])
                    && !$this->client->isEncrypted()
                    && $this->options->isHttp2UpgradeAllowed()
                    && false !== \stripos($headerMap["connection"][0], "upgrade")
                    && \strtolower($headerMap["upgrade"][0]) === "h2c"
                    && false !== $h2cSettings = \base64_decode(\strtr($headerMap["http2-settings"][0], "-_", "+/"), true)
                ) {
                    // Request instance will be overwritten below. This is for sending the switching protocols response.
                    yield $this->write(
                        new Request($this->client, $method, $uri, $headerMap, null, $protocol),
                        new Response(Status::SWITCHING_PROTOCOLS, [
                            "connection" => "upgrade",
                            "upgrade" => "h2c",
                        ])
                    );

                    // Internal upgrade
                    $this->http2 = new Http2Driver($this->options, $this->logger);
                    $parser = $this->http2->setup($this->client, $this->onMessage, $this->write, $h2cSettings);

                    $parser->current(); // Yield from this parser after reading the current request body.

                    // Remove headers that are not related to the HTTP/2 request.
                    foreach ($headers as $index => [$key, $value]) {
                        switch (\strtolower($key)) {
                            case "upgrade":
                            case "connection":
                            case "http2-settings":
                                unset($headers[$index]);
                                break;
                        }
                    }

                    $protocol = "2";
                }

                $this->updateTimeout();

                if (!($isChunked || $contentLength)) {
                    $request = new Request($this->client, $method, $uri, [], null, $protocol);
                    foreach ($headers as [$key, $value]) {
                        $request->addHeader($key, $value);
                    }

                    $this->pendingResponse = ($this->onMessage)($request, $buffer);
                    $request = null; // DO NOT leave a reference to the Request object in the parser!

                    yield $this->pendingResponse; // Wait for response to be generated.

                    continue;
                }

                // HTTP/1.x clients only ever have a single body emitter.
                $this->bodyEmitter = $emitter = new Emitter;
                $trailerDeferred = new Deferred;
                $maxBodySize = $this->options->getBodySizeLimit();

                $body = new RequestBody(
                    new IteratorStream($this->bodyEmitter->iterate()),
                    function (int $bodySize) use (&$maxBodySize) {
                        if ($bodySize > $maxBodySize) {
                            $maxBodySize = $bodySize;
                        }
                    }
                );

                try {
                    $trailers = new Trailers(
                        $trailerDeferred->promise(),
                        isset($headerMap['trailers'])
                            ? \array_map('trim', \explode(',', \implode(',', $headerMap['trailers'])))
                            : []
                    );
                } catch (InvalidHeaderException $exception) {
                    throw new ClientException(
                        $this->client,
                        "Invalid headers field in promises trailers",
                        0,
                        $exception
                    );
                }

                $request = new Request($this->client, $method, $uri, [], $body, $protocol, $trailers);
                foreach ($headers as [$key, $value]) {
                    $request->addHeader($key, $value);
                }

                // Do not yield promise until body is completely read.
                $this->pendingResponse = ($this->onMessage)($request);

                // DO NOT leave a reference to the Request, Trailers, or Body objects within the parser!
                $request = null;
                $trailers = null;
                $body = "";

                if ($isChunked) {
                    $bodySize = 0;
                    while (true) {
                        while (false === ($lineEndPos = \strpos($buffer, "\r\n"))) {
                            if (\strlen($buffer) > 10) {
                                throw new ClientException(
                                    $this->client,
                                    "Bad Request: hex chunk size expected",
                                    Status::BAD_REQUEST
                                );
                            }

                            $buffer .= yield;
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
                                $buffer .= yield;
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
                                    $buffer = (string) \substr($buffer, $trailerPos + 4);
                                    break;
                                }

                                if (\strlen($buffer) > $maxHeaderSize) {
                                    throw new ClientException(
                                        $this->client,
                                        "Bad Request: trailer headers too large",
                                        Status::BAD_REQUEST
                                    );
                                }

                                $buffer .= yield;
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

                                $trailerDeferred->resolve($trailers);
                                $trailerDeferred = null;
                            }

                            break; // finished (chunked loop)
                        }

                        if ($bodySize + $chunkLengthRemaining > $maxBodySize) {
                            do {
                                $remaining = $maxBodySize - $bodySize;
                                $chunkLengthRemaining -= $remaining - \strlen($body);
                                $body .= $buffer;
                                $bodyBufferSize = \strlen($body);

                                while ($bodyBufferSize < $remaining) {
                                    if ($bodyBufferSize) {
                                        $this->updateTimeout();
                                        yield $emitter->emit($body);
                                        $body = "";
                                        $bodySize += $bodyBufferSize;
                                        $remaining -= $bodyBufferSize;
                                        $bodyBufferSize = \strlen($body);
                                    }

                                    if (!$bodyBufferSize) {
                                        $body = yield;
                                        $bodyBufferSize = \strlen($body);
                                    }
                                }

                                if ($remaining) {
                                    $this->updateTimeout();
                                    yield $emitter->emit(\substr($body, 0, $remaining));
                                    $buffer = \substr($body, $remaining);
                                    $body = "";
                                    $bodySize += $remaining;
                                }

                                if ($bodySize !== $maxBodySize) {
                                    continue;
                                }

                                throw new ClientException($this->client, "Payload too large", Status::PAYLOAD_TOO_LARGE);
                            } while ($maxBodySize < $bodySize + $chunkLengthRemaining);
                        }

                        while (true) {
                            $bufferLength = \strlen($buffer);

                            if (!$bufferLength) {
                                $buffer = yield;
                                $bufferLength = \strlen($buffer);
                            }

                            // These first two (extreme) edge cases prevent errors where the packet boundary ends after
                            // the \r and before the \n at the end of a chunk.
                            if ($bufferLength === $chunkLengthRemaining || $bufferLength === $chunkLengthRemaining + 1) {
                                $buffer .= yield;
                                continue;
                            }

                            $this->updateTimeout();

                            if ($bufferLength >= $chunkLengthRemaining + 2) {
                                yield $emitter->emit(\substr($buffer, 0, $chunkLengthRemaining));
                                $buffer = \substr($buffer, $chunkLengthRemaining + 2);

                                $chunkLengthRemaining = null;
                                continue 2; // next chunk (chunked loop)
                            }

                            yield $emitter->emit($buffer);
                            $buffer = "";
                            $chunkLengthRemaining -= $bufferLength;
                        }
                    }

                    if ($body !== "") {
                        yield $emitter->emit($body);
                    }
                } else {
                    $bodySize = 0;
                    $bodyBufferSize = \strlen($buffer);

                    // Note that $maxBodySize may change while looping.
                    while ($bodySize + $bodyBufferSize < \min($maxBodySize, $contentLength)) {
                        if ($bodyBufferSize) {
                            $this->updateTimeout();
                            $buffer = yield $emitter->emit($buffer);
                            $bodySize += $bodyBufferSize;
                        }
                        $buffer .= yield;
                        $bodyBufferSize = \strlen($buffer);
                    }

                    $remaining = \min($maxBodySize, $contentLength) - $bodySize;

                    if ($remaining) {
                        $this->updateTimeout();
                        yield $emitter->emit(\substr($buffer, 0, $remaining));
                        $buffer = \substr($buffer, $remaining);
                    }

                    if ($contentLength > $maxBodySize) {
                        throw new ClientException($this->client, "Payload too large", Status::PAYLOAD_TOO_LARGE);
                    }
                }

                if ($trailerDeferred !== null) {
                    $trailerDeferred->resolve([]);
                    $trailerDeferred = null;
                }

                $this->bodyEmitter = null;
                $emitter->complete();

                $this->updateTimeout();

                yield $this->pendingResponse; // Wait for response to be generated.
            } while (true);
        } catch (ClientException $exception) {
            if ($this->bodyEmitter === null || $this->client->getPendingResponseCount()) {
                // Send an error response only if another response has not already been sent to the request.
                yield new Coroutine($this->sendErrorResponse($exception));
            }
            return;
        } finally {
            if ($this->bodyEmitter !== null) {
                $emitter = $this->bodyEmitter;
                $this->bodyEmitter = null;
                $emitter->fail($exception ?? new ClientException(
                    $this->client,
                    "Client disconnected",
                    Status::REQUEST_TIMEOUT
                ));
            }

            if (isset($trailerDeferred)) {
                $trailerDeferred->fail($exception ?? new ClientException(
                    $this->client,
                    "Client disconnected",
                    Status::REQUEST_TIMEOUT
                ));
                $trailerDeferred = null;
            }
        }
    }

    /**
     * Creates an error response from the error handler and sends that response to the client.
     *
     * @param \Amp\Http\Server\ClientException $exception
     *
     * @return \Generator
     */
    private function sendErrorResponse(ClientException $exception): \Generator
    {
        $message = $exception->getMessage();
        $status = $exception->getCode() ?: Status::BAD_REQUEST;

        /** @var \Amp\Http\Server\Response $response */
        $response = yield $this->errorHandler->handleError($status, $message);

        $response->setHeader("connection", "close");

        yield from $this->send($response);
    }

    /**
     * Filters and updates response headers based on protocol and connection header from the request.
     *
     * @param \Amp\Http\Server\Response $response
     * @param string                    $protocol Request protocol.
     * @param array                     $connection Request connection header.
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
            $headers["keep-alive"] = ["timeout=" . $this->options->getHttp1Timeout()];
        }

        $headers["date"] = [formatDateHeader()];

        return $headers;
    }
}
