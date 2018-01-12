<?php

namespace Aerys\Internal;

use Aerys\ClientException;
use Aerys\HttpStatus;
use Aerys\NullBody;
use Aerys\Response;
use Amp\Loop;
use Amp\Uri\Uri;
use const Aerys\SERVER_TOKEN;

class Http1Driver implements HttpDriver {
    const HEADER_REGEX = "(
        ([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    )x";

    /** @var \Aerys\Internal\Http2Driver */
    private $http2;

    private $resultEmitter;
    private $entityHeaderEmitter;
    private $entityPartEmitter;
    private $entityResultEmitter;
    private $errorEmitter;
    private $responseWriter;

    public function setup(array $parseEmitters, callable $responseWriter) {
        $map = [
            self::RESULT => "resultEmitter",
            self::ENTITY_HEADERS => "entityHeaderEmitter",
            self::ENTITY_PART => "entityPartEmitter",
            self::ENTITY_RESULT => "entityResultEmitter",
            self::ERROR => "errorEmitter",
        ];
        foreach ($parseEmitters as $emitterType => $emitter) {
            foreach ($map as $key => $property) {
                if ($emitterType & $key) {
                    $this->$property = $emitter;
                }
            }
        }
        $this->responseWriter = $responseWriter;
        $this->http2 = new Http2Driver;
        $this->http2->setup($parseEmitters, $responseWriter);
    }

    public function writer(ServerRequest $request, Response $response): \Generator {
        // We need this in here to be able to return HTTP/2.0 writer; if we allow HTTP/1.1 writer to be returned, we have lost
        if (isset($request->headers["upgrade"][0]) &&
            $request->headers["upgrade"][0] === "h2c" &&
            $request->protocol === "1.1" &&
            isset($request->headers["http2-settings"][0]) &&
            false !== $h2cSettings = base64_decode(strtr($request->headers["http2-settings"][0], "-_", "+/"), true)
        ) {
            // Send upgrading response
            $responseWriter = $this->send($request, new Response(new NullBody, [
                "connection" => "Upgrade",
                "upgrade" => "h2c",
            ], HttpStatus::SWITCHING_PROTOCOLS));
            $responseWriter->send(null); // flush before replacing

            // internal upgrade
            $client = $request->client;
            $client->httpDriver = $this->http2;
            $client->requestParser = $client->httpDriver->parser($client, $h2cSettings);

            $client->requestParser->valid(); // start generator

            $request->streamId = 1;
            $client->streamWindow = [];
            $client->streamWindow[$request->streamId] = $client->window;
            $client->streamWindowBuffer[$request->streamId] = "";
            $request->protocol = "2.0";

            /* unnecessary:
            // Make request look HTTP/2 compatible
            $ireq->headers[":scheme"] = $client->isEncrypted ? "https" : "http";
            $ireq->headers[":authority"] = $ireq->headers["host"][0];
            $ireq->headers[":path"] = $ireq->uriPath;
            $ireq->headers[":method"] = $ireq->method;
            $host = \explode(":", $ireq->headers["host"][0]);
            if (count($host) > 1) {
                $ireq->uriPort = array_pop($host);
            }
            $ireq->uriHost = implode(":", $host);
            unset($ireq->headers["host"]);
            */

            return $this->http2->writer($request, $response);
        }

        return $this->send($request, $response);
    }

    private function send(ServerRequest $request, Response $response): \Generator {
        $client = $request->client;

        $status = $response->getStatus();
        $reason = $response->getReason();
        $headers = $this->filter($request, $response->getHeaders(), $response->getPush(), $status);

        $chunked = !isset($headers["content-length"])
            && $request->protocol === "1.1"
            && $status >= 200;

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

        $protocol = $request->protocol ?? "1.1";

        $buffer = "HTTP/{$protocol} {$status} {$reason}\r\n";
        foreach ($headers as $headerField => $headerLines) {
            if ($headerField[0] !== ":") {
                foreach ($headerLines as $headerLine) {
                    /* verify header fields (per RFC) and header values against containing \n */
                    \assert(strpbrk($headerField, "\n\t ()<>@,;:\\\"/[]?={}") === false && strpbrk((string) $headerLine, "\n") === false);
                    $buffer .= "{$headerField}: {$headerLine}\r\n";
                }
            }
        }
        $buffer .= "\r\n";

        if ($request->method === "HEAD") {
            ($this->responseWriter)($client, $buffer, true);
            while (null !== yield); // Ignore body portions written.
        } else {
            do {
                if (\strlen($buffer) >= $client->options->outputBufferSize) {
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

    public function upgradeBodySize(ServerRequest $ireq, int $bodySize) {
        if ($bodySize > ($ireq->maxBodySize ?? $ireq->client->options->maxBodySize)) {
            $ireq->maxBodySize = $bodySize;
        }
    }

    public function parser(Client $client): \Generator {
        $maxHeaderSize = $client->options->maxHeaderSize;
        $maxBodySize = $client->options->maxBodySize;
        $bodyEmitSize = $client->options->ioGranularity;

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
                    ($this->errorEmitter)($client, HttpStatus::REQUEST_HEADER_FIELDS_TOO_LARGE, "Bad Request: header size violation");
                    return;
                }

                $buffer .= yield;
            }

            $startLineEndPos = \strpos($startLineAndHeaders, "\r\n");
            $startLine = substr($startLineAndHeaders, 0, $startLineEndPos);
            $rawHeaders = \substr($startLineAndHeaders, $startLineEndPos + 2);

            if (!\preg_match("/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)$/i", $startLine, $matches)) {
                ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: invalid request line");
                return;
            }

            $method = $matches[1];
            $uri = $matches[2];
            $protocol = $matches[3];

            if ($protocol !== "1.1" && $protocol !== "1.0") {
                // @TODO eventually add an option to disable HTTP/2.0 support???
                if ($protocol === "2.0") {
                    $client->httpDriver = $this->http2;
                    $client->streamWindow = [];
                    $client->requestParser = $client->httpDriver->parser($client);
                    $client->requestParser->send("$startLineAndHeaders\r\n$buffer");
                    return;
                }
                ($this->errorEmitter)($client, HttpStatus::HTTP_VERSION_NOT_SUPPORTED, "Unsupported version {$protocol}");
                break;
            }

            if ($rawHeaders) {
                if (\strpos($rawHeaders, "\n\x20") || \strpos($rawHeaders, "\n\t")) {
                    ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: multi-line headers deprecated by RFC 7230");
                    return;
                }

                if (!\preg_match_all(self::HEADER_REGEX, $rawHeaders, $matches, \PREG_SET_ORDER)) {
                    ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: header syntax violation");
                    return;
                }

                foreach ($matches as list(, $field, $value)) {
                    $headers[$field][] = $value;
                }

                $headers = \array_change_key_case($headers);

                $contentLength = $headers["content-length"][0] ?? null;
                if ($contentLength !== null) {
                    if (!\preg_match("/^(?:0|[1-9][0-9]*)$/", $contentLength)) {
                        ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: invalid content length");
                        return;
                    }

                    $contentLength = (int) $contentLength;
                }

                if (isset($headers["transfer-encoding"])) {
                    $value = strtolower($headers["transfer-encoding"][0]);
                    if (!($isChunked = $value === "chunked") && $value !== "identity") {
                        ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: unsupported transfer-encoding");
                        return;
                    }
                }
            }

            if ($method == "HEAD" || $method == "TRACE" || $method == "OPTIONS" || $contentLength === 0) {
                // No body allowed for these messages
                $hasBody = false;
            } else {
                $hasBody = $isChunked || $contentLength;
            }

            $ireq = new ServerRequest;
            $ireq->client = $client;
            $ireq->headers = $headers;
            $ireq->method = $method;
            $ireq->protocol = $protocol;
            $ireq->trace = $startLineAndHeaders;
            $ireq->target = $uri;
            $ireq->maxBodySize = $maxBodySize;

            $host = $headers["host"][0] ?? ""; // Host header may be set but empty.
            if ($host === "") {
                ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: Invalid host header");
                return;
            }

            if ($uri === "*") {
                $ireq->uri = new Uri($host . ":" . $client->serverPort);
            } elseif (($schemepos = \strpos($uri, "://")) !== false && $schemepos < \strpos($uri, "/")) {
                $ireq->uri = new Uri($uri);
            } else {
                $scheme = $client->isEncrypted ? "https" : "http";
                if (($colon = \strrpos($host, ":")) !== false) {
                    $port = (int) \substr($host, $colon + 1);
                    $host = \substr($host, 0, $colon);
                } else {
                    $port = $client->serverPort;
                }

                $uri = $scheme . "://" . $host . ":" . $port . $uri;
                $ireq->uri = new Uri($uri);
            }

            if (!$hasBody) {
                ($this->resultEmitter)($ireq);
                continue;
            }

            // @TODO Handle HTTP/2 upgrade request.

            ($this->entityHeaderEmitter)($ireq);
            $body = "";

            if ($isChunked) {
                $bodySize = 0;
                while (true) {
                    while (false === ($lineEndPos = \strpos($buffer, "\r\n"))) {
                        if (\strlen($buffer) > 10) {
                            ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: hex chunk size expected");
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
                            ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: hex chunk size expected");
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
                                ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Trailer headers too large");
                                return;
                            }
                        } while (!isset($trailers));

                        if (\strpos($trailers, "\n\x20") || \strpos($trailers, "\n\t")) {
                            ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: multi-line trailers deprecated by RFC 7230");
                            return;
                        }

                        if (!\preg_match_all(self::HEADER_REGEX, $trailers, $matches)) {
                            ($this->errorEmitter)($client, HttpStatus::BAD_REQUEST, "Bad Request: trailer syntax violation");
                            return;
                        }

                        list(, $fields, $values) = $matches;
                        $trailers = [];
                        foreach ($fields as $index => $field) {
                            $trailers[$field][] = $values[$index];
                        }

                        if ($trailers) {
                            $trailers = \array_change_key_case($trailers);

                            foreach (["transfer-encoding", "content-length", "trailer"] as $remove) {
                                unset($trailers[$remove]);
                            }

                            if ($trailers) {
                                $headers = \array_merge($headers, $trailers);
                            }
                        }

                        break; // finished ($is_chunked loop)
                    }

                    if ($bodySize + $chunkLenRemaining > $client->streamWindow) {
                        do {
                            $remaining = $ireq->maxBodySize - $bodySize;
                            $chunkLenRemaining -= $remaining - \strlen($body);
                            $body .= $buffer;
                            $bodyBufferSize = \strlen($body);

                            while ($bodyBufferSize < $remaining) {
                                if ($bodyBufferSize >= $bodyEmitSize) {
                                    ($this->entityPartEmitter)($client, $body);
                                    $body = '';
                                    $bodySize += $bodyBufferSize;
                                    $remaining -= $bodyBufferSize;
                                }
                                $body .= yield;
                                $bodyBufferSize = \strlen($body);
                            }
                            if ($remaining) {
                                ($this->entityPartEmitter)($client, substr($body, 0, $remaining));
                                $buffer = substr($body, $remaining);
                                $body = "";
                                $bodySize += $remaining;
                            }

                            if ($bodySize !== $ireq->maxBodySize) {
                                continue;
                            }

                            ($this->errorEmitter)($client, HttpStatus::PAYLOAD_TOO_LARGE, "Payload too large");
                            return;
                        } while ($ireq->maxBodySize < $bodySize + $chunkLenRemaining);
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
                            ($this->entityPartEmitter)($client, $body);
                            $body = '';
                            $bodySize += $bodyBufferSize;
                            $bodyBufferSize = 0;
                        }

                        if ($bufferLen >= $chunkLenRemaining + 2) {
                            $chunkLenRemaining = null;
                            continue 2; // next chunk ($isChunked loop)
                        }

                        $buffer = yield;
                    }
                }

                if ($body !== "") {
                    ($this->entityPartEmitter)($client, $body);
                }
            } else {
                $bodySize = 0;

                if ($ireq->maxBodySize < $contentLength) {
                    ($this->errorEmitter)($client, HttpStatus::PAYLOAD_TOO_LARGE, "Payload too large");
                    return;
                }

                $bound = $contentLength;
                $bodyBufferSize = \strlen($buffer);

                while ($bodySize + $bodyBufferSize < $bound) {
                    if ($bodyBufferSize >= $bodyEmitSize) {
                        ($this->entityPartEmitter)($client, $buffer);
                        $buffer = '';
                        $bodySize += $bodyBufferSize;
                    }
                    $buffer .= yield;
                    $bodyBufferSize = \strlen($buffer);
                }
                $remaining = $bound - $bodySize;
                if ($remaining) {
                    ($this->entityPartEmitter)($client, substr($buffer, 0, $remaining));
                    $buffer = substr($buffer, $remaining);
                }
            }

            ($this->entityResultEmitter)($client);
        } while (true);
    }

    private function filter(ServerRequest $request, array $headers, array $push, int $status): array {
        $options = $request->client->options;

        if ($options->sendServerToken) {
            $headers["server"] = [SERVER_TOKEN];
        }

        if ($status < 200) {
            return $headers;
        }

        if (!empty($push)) {
            $headers["link"] = [];
            foreach ($push as $url => $pushHeaders) {
                $headers["link"][] = "<$url>; rel=preload";
            }
        }

        $contentLength = $headers["content-length"][0] ?? null;
        $shouldClose = (isset($request->headers["connection"]) && \in_array("close", $request->headers["connection"]))
            || (isset($headers["connection"]) && \in_array("close", $headers["connection"]));

        if ($contentLength !== null) {
            $shouldClose = $shouldClose || $request->protocol === "1.0";
            unset($headers["transfer-encoding"]);
        } elseif ($request->protocol === "1.1") {
            unset($headers["content-length"]);
        } else {
            $shouldClose = true;
        }

        $remainingRequests = $request->client->remainingRequests;
        if ($shouldClose || $remainingRequests <= 0) {
            $headers["connection"] = ["close"];
        } elseif ($remainingRequests < (PHP_INT_MAX >> 1)) {
            $headers["connection"] = ["keep-alive"];
            $keepAlive = "timeout={$options->connectionTimeout}, max={$remainingRequests}";
            $headers["keep-alive"] = [$keepAlive];
        } else {
            $headers["connection"] = ["keep-alive"];
            $keepAlive = "timeout={$options->connectionTimeout}";
            $headers["keep-alive"] = [$keepAlive];
        }

        $headers["date"] = [$request->httpDate];

        return $headers;
    }
}
