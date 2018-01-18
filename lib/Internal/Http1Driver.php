<?php

namespace Aerys\Internal;

use Aerys\Body;
use Aerys\ClientException;
use Aerys\HttpStatus;
use Aerys\NullBody;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Amp\ByteStream\IteratorStream;
use Amp\Emitter;
use Amp\Loop;
use Amp\Uri\Uri;

class Http1Driver implements HttpDriver {
    const HEADER_REGEX = "(
        ([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    )x";

    /** @var \Aerys\Internal\Http2Driver */
    private $http2;

    /** @var callable */
    private $onRequest;

    /** @var callable */
    private $onError;

    /** @var callable */
    private $responseWriter;

    /** @var \Aerys\NullBody */
    private $nullBody;

    /** @var string */
    private $date;

    public function __construct() {
        $this->http2 = new Http2Driver();
        $this->nullBody = new NullBody;
    }

    public function setup(Server $server, callable $onRequest, callable $onError, callable $responseWriter) {
        $server->onTimeUpdate(function (int $time, string $date) {
            $this->date = $date;
        });

        $this->onRequest = $onRequest;
        $this->onError = $onError;
        $this->responseWriter = $responseWriter;
        $this->http2->setup($server, $onRequest, $onError, $responseWriter);
    }

    public function writer(Client $client, Response $response, Request $request = null): \Generator {
        $protocol = $request !== null ? $request->getProtocolVersion() : "1.0";

        $status = $response->getStatus();
        $reason = $response->getReason();

        $headers = $this->filter($client, $response, $protocol, $request ? $request->getHeaderArray("connection") : []);

        $chunked = !isset($headers["content-length"])
            && $protocol === "1.1"
            && $status >= HttpStatus::OK;

        if (!empty($headers["connection"])) {
            foreach ($headers["connection"] as $connection) {
                if (\strcasecmp($connection, "close") === 0) {
                    $client->shouldClose = true;
                    $chunked = false;
                }
            }
        }

        if ($chunked) {
            $headers["transfer-encoding"] = ["chunked"];
        }

        $buffer = "HTTP/{$protocol} {$status} {$reason}\r\n";
        foreach ($headers as $headerField => $headerLines) {
            if ($headerField[0] !== ":") {
                foreach ($headerLines as $headerLine) {
                    /* verify header fields (per RFC) and header values against containing \n */
                    \assert(
                        strpbrk($headerField, "\n\t ()<>@,;:\\\"/[]?={}") === false
                        && strpbrk((string) $headerLine, "\n") === false
                    );
                    $buffer .= "{$headerField}: {$headerLine}\r\n";
                }
            }
        }
        $buffer .= "\r\n";

        if ($request !== null && $request->getMethod() === "HEAD") {
            ($this->responseWriter)($client, $buffer, true);
            while (null !== yield); // Ignore body portions written.
        } else {
            $outputBufferSize = $client->options->getOutputBufferSize();

            do {
                if (\strlen($buffer) >= $outputBufferSize) {
                    ($this->responseWriter)($client, $buffer);
                    $buffer = "";

                    if ($client->isDead & Client::CLOSED_WR) {
                        return;
                    }
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

            ($this->responseWriter)($client, $buffer, true);
        }

        // parserEmitLock check is required to prevent recursive continuation of the parser
        if ($client->requestParser && $client->parserEmitLock && !$client->shouldClose) {
            $client->requestParser->send("");
        }

        if ($client->isDead == Client::CLOSED_RD /* i.e. not CLOSED_WR */ && $client->bodyEmitters) {
            array_pop($client->bodyEmitters)->fail(new ClientException); // just one element with Http1Driver
        }
    }

    public function parser(Client $client): \Generator {
        $maxHeaderSize = $client->options->getMaxHeaderSize();
        $maxBodySize = $client->options->getMaxBodySize();
        $bodyEmitSize = $client->options->getIoGranularity();
        $id = 0;

        $buffer = "";

        do {
            $headers = [];
            $contentLength = null;
            $isChunked = false;

            if ($client->pendingResponses) {
                $client->parserEmitLock = true;

                do {
                    if (\strlen($buffer) > $maxHeaderSize + $maxBodySize) {
                        Loop::disable($client->readWatcher);
                        $buffer .= yield;
                        if (!($client->isDead & Client::CLOSED_RD)) {
                            Loop::enable($client->readWatcher);
                        }
                        break;
                    }

                    $buffer .= yield;
                } while ($client->pendingResponses);

                $client->parserEmitLock = false;
            }

            while (true) {
                $buffer = \ltrim($buffer, "\r\n");

                if ($headerPos = \strpos($buffer, "\r\n\r\n")) {
                    $startLineAndHeaders = \substr($buffer, 0, $headerPos + 2);
                    $buffer = (string) \substr($buffer, $headerPos + 4);
                    break;
                }

                if (\strlen($buffer) > $maxHeaderSize) {
                    ($this->onError)($client, HttpStatus::REQUEST_HEADER_FIELDS_TOO_LARGE, "Bad Request: header size violation");
                    return;
                }

                $buffer .= yield;
            }

            $startLineEndPos = \strpos($startLineAndHeaders, "\r\n");
            $startLine = substr($startLineAndHeaders, 0, $startLineEndPos);
            $rawHeaders = \substr($startLineAndHeaders, $startLineEndPos + 2);

            if (!\preg_match("/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)$/i", $startLine, $matches)) {
                ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: invalid request line");
                return;
            }

            list(, $method, $target, $protocol) = $matches;

            if ($protocol !== "1.1" && $protocol !== "1.0") {
                if ($protocol === "2.0") {
                    if ($client->isEncrypted && ($client->cryptoInfo["alpn_protocol"] ?? null) !== "h2") {
                        ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: ALPN must be h2");
                        return;
                    }

                    // Internal upgrade to HTTP/2.
                    $client->httpDriver = $this->http2;
                    $client->requestParser = $client->httpDriver->parser($client);
                    $client->requestParser->send("$startLineAndHeaders\r\n$buffer");
                    return;
                }

                ($this->onError)($client, HttpStatus::HTTP_VERSION_NOT_SUPPORTED, "Unsupported version {$protocol}");
                break;
            }

            if ($rawHeaders) {
                if (\strpos($rawHeaders, "\n\x20") || \strpos($rawHeaders, "\n\t")) {
                    ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: multi-line headers deprecated by RFC 7230");
                    return;
                }

                if (!\preg_match_all(self::HEADER_REGEX, $rawHeaders, $matches, \PREG_SET_ORDER)) {
                    ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: header syntax violation");
                    return;
                }

                foreach ($matches as list(, $field, $value)) {
                    $headers[$field][] = $value;
                }

                $headers = \array_change_key_case($headers);

                $contentLength = $headers["content-length"][0] ?? null;
                if ($contentLength !== null) {
                    if (!\preg_match("/^(?:0|[1-9][0-9]*)$/", $contentLength)) {
                        ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: invalid content length");
                        return;
                    }

                    $contentLength = (int) $contentLength;
                }

                if (isset($headers["transfer-encoding"])) {
                    $value = strtolower($headers["transfer-encoding"][0]);
                    if (!($isChunked = $value === "chunked") && $value !== "identity") {
                        ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: unsupported transfer-encoding");
                        return;
                    }
                }
            }

            if ($client->options->shouldNormalizeMethodCase()) {
                $method = \strtoupper($method);
            }

            $host = $headers["host"][0] ?? ""; // Host header may be set but empty.
            if ($host === "") {
                ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: Invalid host header");
                return;
            }

            if ($target === "*") {
                $uri = new Uri($host . ":" . $client->serverPort);
            } elseif (($schemepos = \strpos($target, "://")) !== false && $schemepos < \strpos($target, "/")) {
                $uri = new Uri($target);
            } else {
                $scheme = $client->isEncrypted ? "https" : "http";
                if (($colon = \strrpos($host, ":")) !== false) {
                    $port = (int) \substr($host, $colon + 1);
                    $host = \substr($host, 0, $colon);
                } else {
                    $port = $client->serverPort;
                }

                $uri = new Uri($scheme . "://" . $host . ":" . $port . $target);
            }

            // Handle HTTP/2 upgrade request.
            if ($protocol === "1.1" &&
                isset($headers["upgrade"][0], $headers["http2-settings"][0], $headers["connection"][0]) &&
                false !== stripos($headers["connection"][0], "upgrade") &&
                strtolower($headers["upgrade"][0]) === "h2c" &&
                false !== $h2cSettings = base64_decode(strtr($headers["http2-settings"][0], "-_", "+/"), true)
            ) {
                // Request instance will be overwritten below. This is for sending the switching protocols response.
                $request = new Request($method, $uri, $headers, $this->nullBody, $target, $protocol);

                $client->pendingResponses++;
                $responseWriter = $this->writer($client, new Response($this->nullBody, [
                    "connection" => "upgrade",
                    "upgrade" => "h2c",
                ], HttpStatus::SWITCHING_PROTOCOLS), $request);
                $responseWriter->send(null); // Flush before replacing

                // Internal upgrade
                $client->httpDriver = $this->http2;
                $client->requestParser = $client->httpDriver->parser($client, $h2cSettings, true);

                $client->requestParser->current(); // Start the parser to send initial frames.

                // Not needed for HTTP/2 request.
                unset($headers["upgrade"], $headers["connection"], $headers["http2-settings"]);

                // Make request look like HTTP/2 request.
                $headers[":method"][0] = $method;
                $headers[":authority"][0] = $uri->getAuthority();
                $headers[":scheme"][0] = $uri->getScheme();
                $headers[":path"][0] = $target;

                $protocol = "2.0";
                $id = 1; // Initial HTTP/2 stream ID.
            }

            if (!($isChunked || $contentLength)) {
                $request = new Request($method, $uri, $headers, $this->nullBody, $target, $protocol, $id);
                ($this->onRequest)($client, $request);
                continue;
            }

            // HTTP/1.x clients only ever have a single body emitter.
            $client->bodyEmitters[0] = $emitter = new Emitter;

            $body = new Body(
                new IteratorStream($client->bodyEmitters[0]->iterate()),
                function (int $bodySize) use (&$maxBodySize) {
                    if ($bodySize > $maxBodySize) {
                        $maxBodySize = $bodySize;
                    }
                }
            );

            $request = new Request($method, $uri, $headers, $body, $target, $protocol, $id);

            ($this->onRequest)($client, $request);

            $body = "";

            try {
                if ($isChunked) {
                    $bodySize = 0;
                    while (true) {
                        while (false === ($lineEndPos = \strpos($buffer, "\r\n"))) {
                            if (\strlen($buffer) > 10) {
                                unset($client->bodyEmitters[0]);
                                $emitter->fail(new ClientException("Invalid body encoding"));
                                ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: hex chunk size expected");
                                return;
                            }

                            $buffer .= yield;
                        }

                        $line = \substr($buffer, 0, $lineEndPos);
                        $buffer = \substr($buffer, $lineEndPos + 2);
                        $hex = \trim($line);
                        if ($hex !== "0") {
                            $hex = \ltrim($line, "0");

                            if (!\preg_match("/^[1-9A-F][0-9A-F]*?$/i", $hex)) {
                                unset($client->bodyEmitters[0]);
                                $emitter->fail(new ClientException("Invalid body encoding"));
                                ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: hex chunk size expected");
                                return;
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
                                    unset($client->bodyEmitters[0]);
                                    $emitter->fail(new ClientException("Trailer headers too large"));
                                    ($this->onError)($client, HttpStatus::BAD_REQUEST, "Trailer headers too large");
                                    return;
                                }
                            } while (!isset($trailers));

                            if (\strpos($trailers, "\n\x20") || \strpos($trailers, "\n\t")) {
                                unset($client->bodyEmitters[0]);
                                $emitter->fail(new ClientException("Multi-line trailers found"));
                                ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: multi-line trailers deprecated by RFC 7230");
                                return;
                            }

                            if (!\preg_match_all(self::HEADER_REGEX, $trailers, $matches)) {
                                unset($client->bodyEmitters[0]);
                                $emitter->fail(new ClientException("Trailer syntax violation"));
                                ($this->onError)($client, HttpStatus::BAD_REQUEST, "Bad Request: trailer syntax violation");
                                return;
                            }

                            list(, $fields, $values) = $matches;
                            $trailers = [];
                            foreach ($fields as $index => $field) {
                                $trailers[$field][] = $values[$index];
                            }

                            // @TODO Alter Body to support trailer headers.
                            if ($trailers) {
                                $trailers = \array_change_key_case($trailers);

                                foreach (["transfer-encoding", "content-length", "trailer"] as $remove) {
                                    unset($trailers[$remove]);
                                }

                                if ($trailers) {
                                    $headers = \array_merge($headers, $trailers);
                                }
                            }

                            break; // finished (chunked loop)
                        }

                        if ($bodySize + $chunkLenRemaining > $client->streamWindow) {
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

                                unset($client->bodyEmitters[0]);
                                $emitter->fail(new ClientException("Body too large"));
                                ($this->onError)($client, HttpStatus::PAYLOAD_TOO_LARGE, "Payload too large");
                                return;
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
                        unset($client->bodyEmitters[0]);
                        $emitter->fail(new ClientException("Body too large"));
                        ($this->onError)($client, HttpStatus::PAYLOAD_TOO_LARGE, "Payload too large");
                        return;
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

                unset($client->bodyEmitters[0]);
                $emitter->complete();
            } finally {
                if (isset($client->bodyEmitters[0])) {
                    $emitter = $client->bodyEmitters[0];
                    unset($client->bodyEmitters[0]);
                    $emitter->fail(new ClientException("Client disconnected"));
                }
            }
        } while (true);
    }

    private function filter(Client $client, Response $response, string $protocol = "1.0", array $connection = []): array {
        $headers = $response->getHeaders();

        if ($response->getStatus() < HttpStatus::OK) {
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

        $remainingRequests = $client->remainingRequests;
        if ($shouldClose || $remainingRequests <= 0) {
            $headers["connection"] = ["close"];
        } elseif ($remainingRequests < (PHP_INT_MAX >> 1)) {
            $headers["connection"] = ["keep-alive"];
            $keepAlive = "timeout={$client->options->getConnectionTimeout()}, max={$remainingRequests}";
            $headers["keep-alive"] = [$keepAlive];
        } else {
            $headers["connection"] = ["keep-alive"];
            $keepAlive = "timeout={$client->options->getConnectionTimeout()}";
            $headers["keep-alive"] = [$keepAlive];
        }

        $headers["date"] = [$this->date];

        return $headers;
    }
}
