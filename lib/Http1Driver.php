<?php

namespace Aerys;

use Amp\ByteStream\IteratorStream;
use Amp\Emitter;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Rfc7230;
use Amp\Http\Status;
use Amp\Uri\InvalidUriException;
use Amp\Uri\Uri;

class Http1Driver implements HttpDriver {
    /** @var \Aerys\Http2Driver|null */
    private $http2;

    /** @var Client */
    private $client;

    /** @var Options */
    private $options;

    /** @var TimeReference */
    private $timeReference;

    /** @var Emitter|null */
    private $bodyEmitter;

    /** @var int */
    private $pendingResponses = 0;

    /** @var int */
    private $remainingRequests;

    /** @var callable */
    private $onMessage;

    /** @var callable */
    private $write;

    public function __construct(Options $options, TimeReference $timeReference) {
        $this->options = $options;
        $this->timeReference = $timeReference;
        $this->remainingRequests = $this->options->getMaxRequestsPerConnection();
    }

    public function setup(Client $client, callable $onMessage, callable $write) {
        $this->client = $client;
        $this->onMessage = $onMessage;
        $this->write = $write;
    }

    public function writer(Response $response, Request $request = null): \Generator {
        if ($this->http2) {
            yield from $this->http2->writer($response, $request);
            return;
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

        $outputBufferSize = $this->options->getOutputBufferSize();

        do {
            if (\strlen($buffer) >= $outputBufferSize) {
                ($this->write)($buffer);
                $buffer = "";
            }

            if (null === $part = yield) {
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

        ($this->write)($buffer, $shouldClose);

        $this->pendingResponses--;
        $this->remainingRequests--;
    }

    public function parser(): \Generator {
        $maxHeaderSize = $this->options->getMaxHeaderSize();
        $maxBodySize = $this->options->getMaxBodySize();
        $bodyEmitSize = $this->options->getIoGranularity();
        $parser = null;
        $buffer = "";

        do {
            if ($parser !== null) { // May be set from upgrade request or receive of PRI * HTTP/2.0 request.
                /** @var \Generator $parser */
                yield from $parser; // Yield from HTTP/2 parser for duration of connection.
                return;
            }

            $headers = [];
            $contentLength = null;
            $isChunked = false;

            do {
                $buffer = \ltrim($buffer, "\r\n");

                if ($headerPos = \strpos($buffer, "\r\n\r\n")) {
                    $startLineAndHeaders = \substr($buffer, 0, $headerPos + 2);
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

            $startLineEndPos = \strpos($startLineAndHeaders, "\r\n");
            $startLine = substr($startLineAndHeaders, 0, $startLineEndPos);
            $rawHeaders = \substr($startLineAndHeaders, $startLineEndPos + 2);

            if (!\preg_match("/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)$/i", $startLine, $matches)) {
                throw new ClientException("Bad Request: invalid request line", Status::BAD_REQUEST);
            }

            list(, $method, $target, $protocol) = $matches;

            if ($protocol !== "1.1" && $protocol !== "1.0") {
                if ($protocol === "2.0" && $this->options->isHttp2Enabled()) {
                    // Internal upgrade to HTTP/2.
                    $this->http2 = new Http2Driver($this->options, $this->timeReference);
                    $this->http2->setup($this->client, $this->onMessage, $this->write);

                    $parser = $this->http2->parser();
                    $parser->send("$startLineAndHeaders\r\n$buffer");
                    continue; // Yield from the above parser immediately.
                }

                throw new ClientException("Unsupported version {$protocol}", Status::HTTP_VERSION_NOT_SUPPORTED);
            }

            if ($rawHeaders) {
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
            }

            if ($this->options->shouldNormalizeMethodCase()) {
                $method = \strtoupper($method);
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
            $host = \rawurldecode($host);
            $authority = $port ? $host . ":" . $port : $host;

            try {
                if ($target[0] === "/") { // origin-form
                    $uri = new Uri($scheme . "://" . $authority . $target);
                } elseif ($target === "*") { // asterisk-form
                    $uri = new Uri($scheme . "://" . $authority);
                } elseif (\preg_match("#^https?://#i", $target)) { // absolute-form
                    $uri = new Uri($target);

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

                    $uri = new Uri($target);

                    if ($uri->getPath() !== "") {
                        throw new ClientException(
                            "Bad Request: authority-form does not allow a path component in the target",
                            Status::BAD_REQUEST
                        );
                    }
                }
            } catch (InvalidUriException $exception) {
                throw new ClientException("Bad Request: invalid target", Status::BAD_REQUEST, $exception);
            }

            // Handle HTTP/2 upgrade request.
            if ($protocol === "1.1"
                && isset($headers["upgrade"][0], $headers["http2-settings"][0], $headers["connection"][0])
                && $this->options->isHttp2Enabled()
                && false !== stripos($headers["connection"][0], "upgrade")
                && strtolower($headers["upgrade"][0]) === "h2c"
                && false !== $h2cSettings = base64_decode(strtr($headers["http2-settings"][0], "-_", "+/"), true)
            ) {
                // Request instance will be overwritten below. This is for sending the switching protocols response.
                $request = new Request($this->client, $method, $uri, $headers, null, $target, $protocol);

                $this->pendingResponses++;
                $responseWriter = $this->writer(new Response(null, [
                    "connection" => "upgrade",
                    "upgrade" => "h2c",
                ], Status::SWITCHING_PROTOCOLS), $request);
                $responseWriter->send(null); // Flush before replacing

                // Internal upgrade
                $this->http2 = new Http2Driver($this->options, $this->timeReference);
                $this->http2->setup($this->client, $this->onMessage, $this->write);

                $parser = $this->http2->parser($h2cSettings, true);
                $parser->current(); // Yield from this parser after reading the current request body.

                // Not needed for HTTP/2 request.
                unset($headers["upgrade"], $headers["connection"], $headers["http2-settings"]);

                // Make request look like HTTP/2 request.
                $headers[":method"] = [$method];
                $headers[":authority"] = [$uri->getAuthority(false)];
                $headers[":scheme"] = [$uri->getScheme()];
                $headers[":path"] = [$target];

                $protocol = "2.0";
            }

            if (!($isChunked || $contentLength)) {
                $request = new Request(
                    $this->client,
                    $method,
                    $uri,
                    $headers,
                    null,
                    $target,
                    $protocol
                );

                $this->pendingResponses++;

                $buffer .= yield ($this->onMessage)($request); // Wait for response to be fully written.

                continue;
            }

            // HTTP/1.x clients only ever have a single body emitter.
            $this->bodyEmitter = $emitter = new Emitter;

            $body = new Body(
                new IteratorStream($this->bodyEmitter->iterate()),
                function (int $bodySize) use (&$maxBodySize) {
                    if ($bodySize > $maxBodySize) {
                        $maxBodySize = $bodySize;
                    }
                }
            );

            $request = new Request($this->client, $method, $uri, $headers, $body, $target, $protocol);

            $this->pendingResponses++;

            $promise = ($this->onMessage)($request); // Do not yield promise until body is completely read.

            $body = "";

            try {
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

                            if (!\preg_match("/^[1-9A-F][0-9A-F]*?$/i", $hex)) {
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
                                break; // finished ($is_chunked loop)
                            }

                            do {
                                if ($trailerSize = \strpos($buffer, "\r\n\r\n")) {
                                    $trailers = \substr($buffer, 0, $trailerSize + 2);
                                    $buffer = \substr($buffer, $trailerSize + 4);
                                } else {
                                    $buffer .= yield;
                                    $trailerSize = \strlen($buffer);
                                    $trailers = null;
                                }
                                if ($maxHeaderSize > 0 && $trailerSize > $maxHeaderSize) {
                                    throw new ClientException(
                                        "Trailer headers too large",
                                        Status::BAD_REQUEST
                                    );
                                }
                            } while (!isset($trailers));

                            // @TODO Alter Body to support trailer headers.
                            if ($trailers) {
                                try {
                                    $trailers = Rfc7230::parseHeaders($trailers);
                                } catch (InvalidHeaderException $e) {
                                    throw new ClientException("Bad Request: " . $e->getMessage(), Status::BAD_REQUEST);
                                }

                                foreach (["transfer-encoding", "content-length", "trailer"] as $remove) {
                                    unset($trailers[$remove]);
                                }

                                // @TODO: Expose trailers
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
                                        $emitter->emit($body);
                                        $body = '';
                                        $bodySize += $bodyBufferSize;
                                        $remaining -= $bodyBufferSize;
                                    }
                                    $body .= yield;
                                    $bodyBufferSize = \strlen($body);
                                }
                                if ($remaining) {
                                    $emitter->emit(substr($body, 0, $remaining));
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
                                $emitter->emit($body);
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
                        $emitter->emit($body);
                    }
                } else {
                    $bodySize = 0;

                    if ($maxBodySize < $contentLength) {
                        throw new ClientException("Payload too large", Status::PAYLOAD_TOO_LARGE);
                    }

                    $bound = $contentLength;
                    $bodyBufferSize = \strlen($buffer);

                    while ($bodySize + $bodyBufferSize < $bound) {
                        if ($bodyBufferSize >= $bodyEmitSize) {
                            $emitter->emit($buffer);
                            $buffer = '';
                            $bodySize += $bodyBufferSize;
                        }
                        $buffer .= yield;
                        $bodyBufferSize = \strlen($buffer);
                    }
                    $remaining = $bound - $bodySize;
                    if ($remaining) {
                        $emitter->emit(substr($buffer, 0, $remaining));
                        $buffer = substr($buffer, $remaining);
                    }
                }

                $this->bodyEmitter = null;
                $emitter->complete();

                $buffer .= yield $promise; // Wait for response to be fully written.
            } catch (\Throwable $exception) {
                // Catching and rethrowing to set $exception to be used in finally.
                throw $exception;
            } finally {
                if (isset($this->bodyEmitter)) {
                    $emitter = $this->bodyEmitter;
                    $this->bodyEmitter = null;
                    $emitter->fail($exception ?? new ClientException(
                        "Client disconnected",
                        Status::REQUEST_TIMEOUT
                    ));
                }
            }
        } while (true);
    }

    public function pendingRequestCount(): int {
        return $this->http2
            ? $this->http2->pendingRequestCount()
            : ($this->bodyEmitter !== null ? 1 : 0);
    }

    public function pendingResponseCount(): int {
        return $this->http2
            ? $this->http2->pendingResponseCount()
            : $this->pendingResponses;
    }

    private function filter(Response $response, string $protocol = "1.0", array $connection = []): array {
        $headers = $response->getHeaders();

        if ($response->getStatus() < Status::OK) {
            return $headers;
        }

        $push = $response->getPush();

        if (!empty($push)) {
            $headers["link"] = [];
            foreach ($push as $url => $pushHeaders) {
                $headers["link"][] = "<$url>; rel=preload";
            }
        }

        $contentLength = $headers["content-length"][0] ?? null;
        $shouldClose = (\in_array("close", $connection))
            || (isset($headers["connection"]) && \in_array("close", $headers["connection"]));

        if ($contentLength !== null) {
            $shouldClose = $shouldClose || $protocol === "1.0";
            unset($headers["transfer-encoding"]);
        } elseif ($protocol === "1.1") {
            unset($headers["content-length"]);
        } else {
            $shouldClose = true;
        }

        $remainingRequests = $this->remainingRequests;
        if ($shouldClose || $remainingRequests <= 0) {
            $headers["connection"] = ["close"];
        } elseif ($remainingRequests < (PHP_INT_MAX >> 1)) {
            $headers["connection"] = ["keep-alive"];
            $keepAlive = "timeout={$this->options->getConnectionTimeout()}, max={$remainingRequests}";
            $headers["keep-alive"] = [$keepAlive];
        } else {
            $headers["connection"] = ["keep-alive"];
            $keepAlive = "timeout={$this->options->getConnectionTimeout()}";
            $headers["keep-alive"] = [$keepAlive];
        }

        $headers["date"] = [$this->timeReference->getCurrentDate()];

        return $headers;
    }
}
