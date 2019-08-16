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

final class Http1Driver implements HttpDriver
{
    /** @var Http2Driver|null */
    private $http2;

    /** @var Client */
    private $client;

    /** @var Options */
    private $options;

    /** @var TimeReference */
    private $timeReference;

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

    public function __construct(Options $options, TimeReference $timeReference, ErrorHandler $errorHandler, PsrLogger $logger)
    {
        $this->options = $options;
        $this->timeReference = $timeReference;
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

                yield $promise;
            }

            if ($chunked) {
                $buffer .= "0\r\n";

                if ($trailers !== null) {
                    $trailers = yield $trailers->awaitMessage();
                    \assert($trailers instanceof Message);
                    $buffer .= Rfc7230::formatHeaders($trailers->getHeaders());
                }

                $buffer .= "\r\n";
            }

            if ($buffer !== "" || $shouldClose) {
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
                    throw new ClientException("Bad Request: invalid request line", Status::BAD_REQUEST);
                }

                list(, $method, $target, $protocol) = $matches;

                if ($protocol !== "1.1" && $protocol !== "1.0") {
                    if ($protocol === "2.0" && $this->options->isHttp2UpgradeAllowed()) {
                        // Internal upgrade to HTTP/2.
                        $this->http2 = new Http2Driver($this->options, $this->timeReference, $this->logger);
                        $parser = $this->http2->setup($this->client, $this->onMessage, $this->write);

                        $parser->send("$startLine\r\n$rawHeaders\r\n$buffer");
                        continue; // Yield from the above parser immediately.
                    }

                    throw new ClientException("Unsupported version {$protocol}", Status::HTTP_VERSION_NOT_SUPPORTED);
                }

                if (!$rawHeaders) {
                    throw new ClientException("Bad Request: missing host header", Status::BAD_REQUEST);
                }

                try {
                    if ($protocol === "1.0") {
                        // Header folding is deprecated for HTTP/1.1, but allowed in HTTP/1.0
                        $rawHeaders = \preg_replace(Rfc7230::HEADER_FOLD_REGEX, ' ', $rawHeaders);
                    }

                    $headers = Rfc7230::parseHeaders($rawHeaders);
                } catch (InvalidHeaderException $e) {
                    throw new ClientException(
                        "Bad Request: " . $e->getMessage(),
                        Status::BAD_REQUEST
                    );
                }

                if (isset($contentLength["content-length"][1])) {
                    throw new ClientException("Bad Request: multiple content-length headers", Status::BAD_REQUEST);
                }

                $contentLength = $headers["content-length"][0] ?? null;
                if ($contentLength !== null) {
                    if (!\preg_match("/^(?:0|[1-9][0-9]*)$/", $contentLength)) {
                        throw new ClientException("Bad Request: invalid content length", Status::BAD_REQUEST);
                    }

                    $contentLength = (int) $contentLength;
                }

                if (isset($headers["transfer-encoding"])) {
                    $value = \strtolower(\implode(', ', $headers["transfer-encoding"]));
                    if (!($isChunked = $value === "chunked") && $value !== "identity") {
                        throw new ClientException(
                            "Bad Request: unsupported transfer-encoding",
                            Status::BAD_REQUEST
                        );
                    }
                }

                if (!isset($headers["host"][0])) {
                    throw new ClientException("Bad Request: missing host header", Status::BAD_REQUEST);
                }

                if (isset($headers["host"][1])) {
                    throw new ClientException("Bad Request: multiple host headers", Status::BAD_REQUEST);
                }

                if (!\preg_match("#^([A-Z\d\.\-]+|\[[\d:]+\])(?::([1-9]\d*))?$#i", $headers["host"][0], $matches)) {
                    throw new ClientException("Bad Request: invalid host header", Status::BAD_REQUEST);
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
                                "Bad Request: target host mis-matched to host header",
                                Status::BAD_REQUEST
                            );
                        }

                        if ($uri->getPath() === "") {
                            throw new ClientException(
                                "Bad Request: no request path provided in target",
                                Status::BAD_REQUEST
                            );
                        }
                    } else { // authority-form
                        if ($method !== "CONNECT") {
                            throw new ClientException(
                                "Bad Request: authority-form only valid for CONNECT requests",
                                Status::BAD_REQUEST
                            );
                        }

                        if (!\preg_match("#^([A-Z\d\.\-]+|\[[\d:]+\]):([1-9]\d*)$#i", $target, $matches)) {
                            throw new ClientException(
                                "Bad Request: invalid connect target",
                                Status::BAD_REQUEST
                            );
                        }

                        $uri = Uri\Http::createFromComponents([
                            "host" => $matches[1],
                            "port" => (int) $matches[2],
                        ]);
                    }
                } catch (Uri\UriException $exception) {
                    throw new ClientException("Bad Request: invalid target", Status::BAD_REQUEST, $exception);
                }

                if (isset($headers["expect"][0]) && \strtolower($headers["expect"][0]) === "100-continue") {
                    yield $this->write(
                        new Request($this->client, $method, $uri, $headers, null, $protocol),
                        new Response(Status::CONTINUE, [])
                    );
                }

                // Handle HTTP/2 upgrade request.
                if ($protocol === "1.1"
                    && isset($headers["upgrade"][0], $headers["http2-settings"][0], $headers["connection"][0])
                    && !$this->client->isEncrypted()
                    && $this->options->isHttp2UpgradeAllowed()
                    && false !== \stripos($headers["connection"][0], "upgrade")
                    && \strtolower($headers["upgrade"][0]) === "h2c"
                    && false !== $h2cSettings = \base64_decode(\strtr($headers["http2-settings"][0], "-_", "+/"), true)
                ) {
                    // Request instance will be overwritten below. This is for sending the switching protocols response.
                    yield $this->write(
                        new Request($this->client, $method, $uri, $headers, null, $protocol),
                        new Response(Status::SWITCHING_PROTOCOLS, [
                            "connection" => "upgrade",
                            "upgrade" => "h2c",
                        ])
                    );

                    // Internal upgrade
                    $this->http2 = new Http2Driver($this->options, $this->timeReference, $this->logger);
                    $parser = $this->http2->setup($this->client, $this->onMessage, $this->write, $h2cSettings);

                    $parser->current(); // Yield from this parser after reading the current request body.

                    // Not needed for HTTP/2 request.
                    unset($headers["upgrade"], $headers["connection"], $headers["http2-settings"]);

                    // Make request look like HTTP/2 request.
                    $headers[":method"] = [$method];
                    $headers[":authority"] = [$uri->getAuthority()];
                    $headers[":scheme"] = [$uri->getScheme()];
                    $headers[":path"] = [$target];

                    $protocol = "2.0";
                }

                if (!($isChunked || $contentLength)) {
                    // Wait for response to be fully written.
                    yield $this->pendingResponse = ($this->onMessage)(new Request(
                        $this->client,
                        $method,
                        $uri,
                        $headers,
                        null,
                        $protocol
                    ), $buffer);

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

                $trailers = new Trailers(
                    $trailerDeferred->promise(),
                    isset($headers['trailers'])
                        ? \array_map('trim', \explode(',', \implode(',', $headers['trailers'])))
                        : []
                );

                // Do not yield promise until body is completely read.
                $this->pendingResponse = ($this->onMessage)(new Request(
                    $this->client,
                    $method,
                    $uri,
                    $headers,
                    $body,
                    $protocol,
                    $trailers
                ));

                // DO NOT leave a reference to the Request, Trailers, or Body objects within the parser!

                $trailers = null;
                $body = "";

                if ($isChunked) {
                    $bodySize = 0;
                    while (true) {
                        while (false === ($lineEndPos = \strpos($buffer, "\r\n"))) {
                            if (\strlen($buffer) > 10) {
                                throw new ClientException(
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
                                    throw new ClientException("Bad Request: " . $e->getMessage(), Status::BAD_REQUEST);
                                }

                                if (\array_intersect_key($trailers, Trailers::DISALLOWED_TRAILERS)) {
                                    throw new ClientException(
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
                                    yield $emitter->emit(\substr($body, 0, $remaining));
                                    $buffer = \substr($body, $remaining);
                                    $body = "";
                                    $bodySize += $remaining;
                                }

                                if ($bodySize !== $maxBodySize) {
                                    continue;
                                }

                                throw new ClientException("Payload too large", Status::PAYLOAD_TOO_LARGE);
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

                            if ($bufferLength >= $chunkLengthRemaining + 2) {
                                yield $emitter->emit(\substr($buffer, 0, $chunkLengthRemaining));
                                $buffer = \substr($buffer, $chunkLengthRemaining + 2);
                            } else {
                                yield $emitter->emit($buffer);
                                $buffer = "";
                                $chunkLengthRemaining -= $bufferLength;
                            }

                            if ($bufferLength >= $chunkLengthRemaining + 2) {
                                $chunkLengthRemaining = null;
                                continue 2; // next chunk (chunked loop)
                            }
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
                            $buffer = yield $emitter->emit($buffer);
                            $bodySize += $bodyBufferSize;
                        }
                        $buffer .= yield;
                        $bodyBufferSize = \strlen($buffer);
                    }

                    $remaining = \min($maxBodySize, $contentLength) - $bodySize;

                    if ($remaining) {
                        yield $emitter->emit(\substr($buffer, 0, $remaining));
                        $buffer = \substr($buffer, $remaining);
                    }

                    if ($contentLength > $maxBodySize) {
                        throw new ClientException("Payload too large", Status::PAYLOAD_TOO_LARGE);
                    }
                }

                if ($trailerDeferred !== null) {
                    $trailerDeferred->resolve([]);
                    $trailerDeferred = null;
                }

                $this->bodyEmitter = null;
                $emitter->complete();

                yield $this->pendingResponse; // Wait for response to be fully written.
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
                    "Client disconnected",
                    Status::REQUEST_TIMEOUT
                ));
            }

            if (isset($trailerDeferred)) {
                $trailerDeferred->fail($exception ?? new ClientException(
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

        $push = $response->getPush();

        if (!empty($push)) {
            $headers["link"] = [];
            foreach ($push as list($pushUri, $pushHeaders)) {
                $headers["link"][] = "<$pushUri>; rel=preload";
            }
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
            $keepAlive = "timeout={$this->options->getConnectionTimeout()}";
            $headers["keep-alive"] = [$keepAlive];
        }

        $headers["date"] = [$this->timeReference->getCurrentDate()];

        return $headers;
    }
}
