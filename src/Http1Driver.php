<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Rfc7230;
use Amp\Http\Status;
use Amp\Promise;
use League\Uri;

class Http1Driver implements HttpDriver {
    /** @see https://tools.ietf.org/html/rfc7230#section-4.1.2 */
    const DISALLOWED_TRAILERS = [
        "authorization",
        "content-encoding",
        "content-length",
        "content-range",
        "content-type",
        "cookie",
        "expect",
        "host",
        "pragma",
        "proxy-authenticate",
        "proxy-authorization",
        "range",
        "te",
        "trailer",
        "transfer-encoding",
        "www-authenticate",
    ];

    /** @var \Amp\Http\Server\Http2Driver|null */
    private $http2;

    /** @var Client */
    private $client;

    /** @var Options */
    private $options;

    /** @var TimeReference */
    private $timeReference;

    /** @var Emitter|null */
    private $bodyEmitter;

    /** @var callable */
    private $onMessage;

    /** @var callable */
    private $write;

    /** @var \Amp\Http\Server\ErrorHandler */
    private $errorHandler;

    /** @var \Amp\Promise|null */
    private $lastWrite;

    public function __construct(Options $options, TimeReference $timeReference, ErrorHandler $errorHandler) {
        $this->options = $options;
        $this->timeReference = $timeReference;
        $this->errorHandler = $errorHandler;
    }

    /** {@inheritdoc} */
    public function setup(Client $client, callable $onMessage, callable $write): \Generator {
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
    public function writer(Response $response, Request $request = null): Promise {
        if ($this->http2) {
            return $this->http2->writer($response, $request);
        }

        return $this->lastWrite = new Coroutine($this->send($response, $request));
    }

    /**
     * HTTP/1.x response writer.
     *
     * @param \Aerys\Response $response
     * @param \Aerys\Request|null $request
     *
     * @return \Generator
     */
    private function send(Response $response, Request $request = null): \Generator {
        \assert($this->client, "The driver has not been setup; call setup first");

        if ($this->lastWrite) {
            yield $this->lastWrite; // Prevent writing another response until the first is finished.
        }

        $shouldClose = false;

        $protocol = $request !== null ? $request->getProtocolVersion() : "1.0";

        $status = $response->getStatus();
        $reason = $response->getReason();

        $headers = $this->filter($response, $protocol, $request ? $request->getHeaderArray("connection") : []);

        $chunked = !isset($headers["content-length"])
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
            ($this->write)($buffer, $shouldClose);
            return;
        }

        $body = $response->getBody();
        $outputBufferSize = $this->options->getOutputBufferSize();
        $part = ""; // Required for the finally, not directly overwritten, even if your IDE says otherwise.

        try {
            do {
                if (\strlen($buffer) > $outputBufferSize) {
                    yield ($this->write)($buffer);
                    $buffer = "";
                }

                if (null === $part = yield $body->read()) {
                    break;
                }

                if ($chunked && $length = \strlen($part)) {
                    $buffer .= \sprintf("%x\r\n%s\r\n", $length, $part);
                } else {
                    $buffer .= $part;
                }
            } while (true);

            if ($chunked) {
                $buffer .= "0\r\n\r\n";
            }

            yield ($this->write)($buffer, $shouldClose);
        } catch (ClientException $exception) {
            return; // Client will be closed in finally.
        } finally {
            if ($part !== null) {
                $this->client->close();
            }
        }
    }

    private function parser(): \Generator {
        $maxHeaderSize = $this->options->getMaxHeaderSize();
        $bodyEmitSize = $this->options->getInputBufferSize();
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
                        $this->http2 = new Http2Driver($this->options, $this->timeReference);
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
                    $headers = Rfc7230::parseHeaders($rawHeaders);
                } catch (InvalidHeaderException $e) {
                    throw new ClientException(
                        "Bad Request: " . $e->getMessage(),
                        Status::BAD_REQUEST
                    );
                }

                $contentLength = $headers["content-length"][0] ?? null;
                if ($contentLength !== null) {
                    if (!\preg_match("/^(?:0|[1-9][0-9]*)$/", $contentLength)) {
                        throw new ClientException("Bad Request: invalid content length", Status::BAD_REQUEST);
                    }

                    $contentLength = (int) $contentLength;
                }

                if (isset($headers["transfer-encoding"])) {
                    $value = strtolower($headers["transfer-encoding"][0]);
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
                $port = isset($matches[2]) ? (int) $matches[2] : $this->client->getLocalPort();
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
                            "host"   => $host,
                            "port"   => $port,
                            "path"   => $target,
                            "query"  => $query,
                        ]);
                    } elseif ($target === "*") { // asterisk-form
                        $uri = Uri\Http::createFromComponents([
                            "scheme" => $scheme,
                            "host"   => $host,
                            "port"   => $port,
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

                        $uri = Uri\Http::createFromString($target);

                        if ($uri->getPath() !== "") {
                            throw new ClientException(
                                "Bad Request: authority-form does not allow a path component in the target",
                                Status::BAD_REQUEST
                            );
                        }
                    }
                } catch (Uri\UriException $exception) {
                    throw new ClientException("Bad Request: invalid target", Status::BAD_REQUEST, $exception);
                }

                if (isset($headers["expect"][0]) && \strtolower($headers["expect"][0]) === "100-continue") {
                    $buffer .= yield $this->writer(
                        new Response(new InMemoryStream, [], Status::CONTINUE),
                        new Request($this->client, $method, $uri, $headers, null, $protocol)
                    );
                }

                // Handle HTTP/2 upgrade request.
                if ($protocol === "1.1"
                    && isset($headers["upgrade"][0], $headers["http2-settings"][0], $headers["connection"][0])
                    && !$this->client->isEncrypted()
                    && $this->options->isHttp2UpgradeAllowed()
                    && false !== stripos($headers["connection"][0], "upgrade")
                    && strtolower($headers["upgrade"][0]) === "h2c"
                    && false !== $h2cSettings = base64_decode(strtr($headers["http2-settings"][0], "-_", "+/"), true)
                ) {
                    // Request instance will be overwritten below. This is for sending the switching protocols response.
                    $buffer .= yield $this->writer(
                        new Response(new InMemoryStream, [
                            "connection" => "upgrade",
                            "upgrade"    => "h2c",
                        ], Status::SWITCHING_PROTOCOLS),
                        new Request($this->client, $method, $uri, $headers, null, $protocol)
                    );

                    // Internal upgrade
                    $this->http2 = new Http2Driver($this->options, $this->timeReference);
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
                    $buffer .= yield ($this->onMessage)(new Request(
                        $this->client,
                        $method,
                        $uri,
                        $headers,
                        null,
                        $protocol
                    ));

                    continue;
                }

                // HTTP/1.x clients only ever have a single body emitter.
                $this->bodyEmitter = $emitter = new Emitter;
                $trailerDeferred = new Deferred;
                $maxBodySize = $this->options->getMaxBodySize();

                $body = new Body(
                    new IteratorStream($this->bodyEmitter->iterate()),
                    function (int $bodySize) use (&$maxBodySize) {
                        if ($bodySize > $maxBodySize) {
                            $maxBodySize = $bodySize;
                        }
                    },
                    $trailerDeferred->promise()
                );

                // Do not yield promise until body is completely read.
                $promise = ($this->onMessage)(new Request(
                    $this->client,
                    $method,
                    $uri,
                    $headers,
                    $body,
                    $protocol
                ));

                // DO NOT leave a reference to the Request or Body objects within the parser!

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

                        $chunkLenRemaining = \hexdec($hex);

                        if ($chunkLenRemaining === 0) {
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

                                if (\array_intersect_key($trailers, self::DISALLOWED_TRAILERS)) {
                                    throw new ClientException(
                                        "Trailer section contains disallowed headers",
                                        Status::BAD_REQUEST
                                    );
                                }

                                $trailerDeferred->resolve(new Trailers($trailers));
                                $trailerDeferred = null;
                            }

                            break; // finished (chunked loop)
                        }

                        if ($bodySize + $chunkLenRemaining > $maxBodySize) {
                            do {
                                $remaining = $maxBodySize - $bodySize;
                                $chunkLenRemaining -= $remaining - \strlen($body);
                                $body .= $buffer;
                                $bodyBufferSize = \strlen($body);

                                while ($bodyBufferSize < $remaining) {
                                    if ($bodyBufferSize >= $bodyEmitSize) {
                                        $buffer .= yield $emitter->emit($body);
                                        $body = '';
                                        $bodySize += $bodyBufferSize;
                                        $remaining -= $bodyBufferSize;
                                    }
                                    $body .= yield;
                                    $bodyBufferSize = \strlen($body);
                                }
                                if ($remaining) {
                                    $body .= yield $emitter->emit(substr($body, 0, $remaining));
                                    $buffer = substr($body, $remaining);
                                    $body = "";
                                    $bodySize += $remaining;
                                }

                                if ($bodySize !== $maxBodySize) {
                                    continue;
                                }

                                throw new ClientException("Payload too large", Status::PAYLOAD_TOO_LARGE);
                            } while ($maxBodySize < $bodySize + $chunkLenRemaining);
                        }

                        $bodyBufferSize = 0;

                        while (true) {
                            $bufferLen = \strlen($buffer);
                            // These first two (extreme) edge cases prevent errors where the packet boundary ends after
                            // the \r and before the \n at the end of a chunk.
                            if ($bufferLen === $chunkLenRemaining || $bufferLen === $chunkLenRemaining + 1) {
                                $buffer .= yield;
                                continue;
                            } elseif ($bufferLen >= $chunkLenRemaining + 2) {
                                $body .= substr($buffer, 0, $chunkLenRemaining);
                                $buffer = substr($buffer, $chunkLenRemaining + 2);
                                $bodyBufferSize += $chunkLenRemaining;
                            } else {
                                $body .= $buffer;
                                $bodyBufferSize += $bufferLen;
                                $chunkLenRemaining -= $bufferLen;
                            }

                            if ($bodyBufferSize >= $bodyEmitSize) {
                                $buffer .= yield $emitter->emit($body);
                                $body = '';
                                $bodySize += $bodyBufferSize;
                                $bodyBufferSize = 0;
                            }

                            if ($bufferLen >= $chunkLenRemaining + 2) {
                                $chunkLenRemaining = null;
                                continue 2; // next chunk (chunked loop)
                            }

                            $buffer = yield;
                        }
                    }

                    if ($body !== "") {
                        $buffer .= yield $emitter->emit($body);
                    }
                } else {
                    $bodySize = 0;
                    $bodyBufferSize = \strlen($buffer);

                    // Note that $maxBodySize may change while looping.
                    while ($bodySize + $bodyBufferSize < \min($maxBodySize, $contentLength)) {
                        if ($bodyBufferSize >= $bodyEmitSize) {
                            $buffer = yield $emitter->emit($buffer);
                            $bodySize += $bodyBufferSize;
                        }
                        $buffer .= yield;
                        $bodyBufferSize = \strlen($buffer);
                    }

                    $remaining = \min($maxBodySize, $contentLength) - $bodySize;

                    if ($remaining) {
                        $buffer .= yield $emitter->emit(substr($buffer, 0, $remaining));
                        $buffer = substr($buffer, $remaining);
                    }

                    if ($contentLength > $maxBodySize) {
                        throw new ClientException("Payload too large", Status::PAYLOAD_TOO_LARGE);
                    }
                }

                if ($trailerDeferred !== null) {
                    $trailerDeferred->resolve(new Trailers([]));
                    $trailerDeferred = null;
                }

                $this->bodyEmitter = null;
                $emitter->complete();

                $buffer .= yield $promise; // Wait for response to be fully written.
            } while (true);
        } catch (ClientException $exception) {
            if ($this->bodyEmitter === null || $this->client->pendingResponseCount()) {
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
     * @param \Aerys\ClientException $exception
     *
     * @return \Generator
     */
    private function sendErrorResponse(ClientException $exception): \Generator {
        $message = $exception->getMessage();
        $status = $exception->getCode() ?: Status::BAD_REQUEST;

        /** @var \Amp\Http\Server\Response $response */
        $response = yield $this->errorHandler->handle($status, $message);

        $response->setHeader("connection", "close");

        yield from $this->writer($response);
    }

    public function pendingRequestCount(): int {
        if ($this->bodyEmitter) {
            return 1;
        }

        if ($this->http2) {
            return $this->http2->pendingRequestCount();
        }

        return 0;
    }

    /**
     * Filters and updates response headers based on protocol and connection header from the request.
     *
     * @param \Aerys\Response $response
     * @param string $protocol Request protocol.
     * @param array $connection Request connection header.
     *
     * @return string[][] Response headers to be written.
     */
    private function filter(Response $response, string $protocol = "1.0", array $connection = []): array {
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
