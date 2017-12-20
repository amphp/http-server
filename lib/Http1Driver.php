<?php

namespace Aerys;

use Amp\Deferred;
use Amp\Loop;

class Http1Driver implements HttpDriver {
    const HEADER_REGEX = "(
        ([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    )x";

    private $http2;
    private $resultEmitter;
    private $entityHeaderEmitter;
    private $entityPartEmitter;
    private $entityResultEmitter;
    private $sizeWarningEmitter;
    private $errorEmitter;
    private $responseWriter;

    public function setup(array $parseEmitters, callable $responseWriter) {
        $map = [
            self::RESULT => "resultEmitter",
            self::ENTITY_HEADERS => "entityHeaderEmitter",
            self::ENTITY_PART => "entityPartEmitter",
            self::ENTITY_RESULT => "entityResultEmitter",
            self::SIZE_WARNING => "sizeWarningEmitter",
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

    public function filters(InternalRequest $ireq, array $userFilters): array {
        // We need this in filters to be able to return HTTP/2.0 filters; if we allow HTTP/1.1 filters to be returned, we have lost
        if (isset($ireq->headers["upgrade"][0]) &&
            $ireq->headers["upgrade"][0] === "h2c" &&
            $ireq->protocol === "1.1" &&
            isset($ireq->headers["http2-settings"][0]) &&
            false !== $h2cSettings = base64_decode(strtr($ireq->headers["http2-settings"][0], "-_", "+/"), true)
        ) {
            // Send upgrading response
            $ireq->responseWriter->send([
                ":status" => HTTP_STATUS["SWITCHING_PROTOCOLS"],
                ":reason" => "Switching Protocols",
                "connection" => ["Upgrade"],
                "upgrade" => ["h2c"],
            ]);
            $ireq->responseWriter->send(false); // flush before replacing

            // internal upgrade
            $client = $ireq->client;
            $client->httpDriver = $this->http2;
            $client->requestParser = $client->httpDriver->parser($client, $h2cSettings);

            $client->requestParser->valid(); // start generator

            $ireq->responseWriter = $client->httpDriver->writer($ireq);
            $ireq->streamId = 1;
            $client->streamWindow = [];
            $client->streamWindow[$ireq->streamId] = $client->window;
            $client->streamWindowBuffer[$ireq->streamId] = "";
            $ireq->protocol = "2.0";

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

            return $client->httpDriver->filters($ireq, $userFilters);
        }

        $filters = [
            [$this, "responseInitFilter"],
        ];
        if ($userFilters) {
            $filters = array_merge($filters, $userFilters);
        }
        if ($ireq->client->options->deflateEnable) {
            $filters[] = '\Aerys\deflateResponseFilter';
        }
        if ($ireq->protocol === "1.1") {
            $filters[] = [$this, "chunkedResponseFilter"];
        }
        if ($ireq->method === "HEAD") {
            $filters[] = '\Aerys\nullBodyResponseFilter';
        }

        return $filters;
    }

    public function writer(InternalRequest $ireq): \Generator {
        $client = $ireq->client;
        $msgs = "";

        try {
            $headers = yield;

            if (!empty($headers["connection"])) {
                foreach ($headers["connection"] as $connection) {
                    if (\strcasecmp($connection, "close") === 0) {
                        $client->shouldClose = true;
                    }
                }
            }

            $lines = ["HTTP/{$ireq->protocol} {$headers[":status"]} {$headers[":reason"]}"];
            unset($headers[":status"], $headers[":reason"]);
            foreach ($headers as $headerField => $headerLines) {
                if ($headerField[0] !== ":") {
                    foreach ($headerLines as $headerLine) {
                        /* verify header fields (per RFC) and header values against containing \n */
                        \assert(strpbrk($headerField, "\n\t ()<>@,;:\\\"/[]?={}") === false && strpbrk((string) $headerLine, "\n") === false);
                        $lines[] = "{$headerField}: {$headerLine}";
                    }
                }
            }
            $lines[] = "\r\n";
            $msgPart = \implode("\r\n", $lines);

            do {
                $msgs .= $msgPart;

                if ($msgPart === false || \strlen($msgs) >= $client->options->outputBufferSize) {
                    $client->writeBuffer .= $msgs;
                    ($this->responseWriter)($client);
                    $msgs = "";

                    if ($client->isDead & Client::CLOSED_WR) {
                        while (true) {
                            yield;
                        }
                    }

                    $client->bufferSize = \strlen($client->writeBuffer);
                    if ($client->bufferDeferred) {
                        if ($client->bufferSize <= $client->options->softStreamCap) {
                            $client->bufferDeferred->resolve();
                        }
                    } elseif ($client->bufferSize > $client->options->softStreamCap) {
                        $client->bufferDeferred = new Deferred;
                    }
                }
            } while (($msgPart = yield) !== null);
        } finally {
            $client->writeBuffer .= $msgs;

            if (!isset($headers) || $msgPart !== null) { // request was aborted
                $client->shouldClose = true;
                // bodyEmitters are failed by the Server when releasing the client
            }

            ($this->responseWriter)($client, $final = true);
        }

        // parserEmitLock check is required to prevent recursive continuation of the parser
        if ($client->requestParser && $client->parserEmitLock && !$client->shouldClose) {
            $client->requestParser->send(false);
        }

        if ($client->isDead == Client::CLOSED_RD /* i.e. not CLOSED_WR */ && $client->bodyEmitters) {
            array_pop($client->bodyEmitters)->fail(new ClientException); // just one element with Http1Driver
        }
    }

    public function upgradeBodySize(InternalRequest $ireq) {
        $client = $ireq->client;
        if ($client->bodyEmitters) {
            $client->streamWindow = $ireq->maxBodySize;
            if ($client->parserEmitLock) {
                $client->requestParser->send("");
            }
        }
    }

    public function parser(Client $client): \Generator {
        $maxHeaderSize = $client->options->maxHeaderSize;
        $bodyEmitSize = $client->options->ioGranularity;

        $buffer = "";

        do {
            $client->streamWindow = $client->options->maxBodySize;

            $headers = [];
            $contentLength = null;
            $isChunked = false;

            if ($client->pendingResponses) {
                $client->parserEmitLock = true;

                do {
                    if (\strlen($buffer) > $maxHeaderSize + $client->streamWindow) {
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

            while (1) {
                $buffer = \ltrim($buffer, "\r\n");

                if ($headerPos = \strpos($buffer, "\r\n\r\n")) {
                    $startLineAndHeaders = \substr($buffer, 0, $headerPos + 2);
                    $buffer = (string) \substr($buffer, $headerPos + 4);
                    break;
                } elseif (\strlen($buffer) > $maxHeaderSize) {
                    ($this->errorEmitter)($client, HTTP_STATUS["REQUEST_HEADER_FIELDS_TOO_LARGE"], "Bad Request: header size violation");
                    return;
                }

                $buffer .= yield;
            }

            $startLineEndPos = \strpos($startLineAndHeaders, "\n");
            $startLine = \rtrim(substr($startLineAndHeaders, 0, $startLineEndPos), "\r\n");
            $rawHeaders = \substr($startLineAndHeaders, $startLineEndPos + 1);

            if (!$method = \strtok($startLine, " ")) {
                ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Bad Request: invalid request line");
                return;
            }

            $uri = \strtok(" ");
            if (!$uri) {
                ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Bad Request: invalid request line");
                return;
            }

            $protocol = \strtok(" ");
            if (!is_string($protocol) || stripos($protocol, "HTTP/") !== 0) {
                ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Bad Request: invalid request line");
                return;
            }

            $protocol = \substr($protocol, 5);

            if ($protocol !== "1.1" && $protocol !== "1.0") {
                // @TODO eventually add an option to disable HTTP/2.0 support???
                if ($protocol === "2.0") {
                    $client->httpDriver = $this->http2;
                    $client->streamWindow = [];
                    $client->requestParser = $client->httpDriver->parser($client);
                    $client->requestParser->send("$startLineAndHeaders\r\n$buffer");
                    return;
                }
                ($this->errorEmitter)($client, HTTP_STATUS["HTTP_VERSION_NOT_SUPPORTED"], "Unsupported version {$protocol}");
                break;
            }

            if ($rawHeaders) {
                if (\strpos($rawHeaders, "\n\x20") || \strpos($rawHeaders, "\n\t")) {
                    ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Bad Request: multi-line headers deprecated by RFC 7230");
                    return;
                }

                if (!\preg_match_all(self::HEADER_REGEX, $rawHeaders, $matches, \PREG_SET_ORDER)) {
                    ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Bad Request: header syntax violation");
                    return;
                }

                foreach ($matches as list(, $field, $value)) {
                    $headers[$field][] = $value;
                }

                $headers = \array_change_key_case($headers);

                $contentLength = $headers["content-length"][0] ?? null;

                if (isset($headers["transfer-encoding"])) {
                    $value = $headers["transfer-encoding"][0];
                    $isChunked = (bool) \strcasecmp($value, "identity");
                }

                // @TODO validate that the bytes in matched headers match the raw input. If not there is a syntax error.
            }

            if ($method == "HEAD" || $method == "TRACE" || $method == "OPTIONS" || $contentLength === 0) {
                // No body allowed for these messages
                $hasBody = false;
            } else {
                $hasBody = $isChunked || $contentLength;
            }

            $ireq = new InternalRequest;
            $ireq->client = $client;
            $ireq->headers = $headers;
            $ireq->method = $method;
            $ireq->protocol = $protocol;
            $ireq->trace = $startLineAndHeaders;
            if (($schemepos = \strpos($uri, "://")) !== false && $schemepos < \strpos($uri, "/")) {
                $uri = \parse_url($uri);
                $ireq->uriScheme = $uri["scheme"];
                $host = $uri["host"];
                $ireq->uriPort = isset($uri["port"]) ? (int) $uri["port"] : $client->serverPort;
                $ireq->uriQuery = $uri["query"] ?? "";
                $uri = empty($uri["path"]) ? "/" : $uri["path"];
                $ireq->uri = $ireq->uriQuery == "" ? $uri : "$uri?{$ireq->uriQuery}";
            } else {
                $ireq->uri = $uri;
                $ireq->uriScheme = $client->isEncrypted ? "https" : "http";
                $host = $headers["host"][0] ?? "";
                if (($colon = \strrpos($host, ":")) !== false) {
                    $ireq->uriPort = (int) \substr($host, $colon + 1);
                    $host = \substr($host, 0, $colon);
                } else {
                    $ireq->uriPort = $client->serverPort;
                }
                if (\strpos($uri, '?') !== false) {
                    list($uri, $ireq->uriQuery) = \explode("?", $uri, 2);
                }
            }
            $ireq->uriHost = \rawurldecode($host);
            $ireq->uriPath = \rawurldecode($uri);

            if (!$hasBody) {
                ($this->resultEmitter)($ireq);
                continue;
            }

            ($this->entityHeaderEmitter)($ireq);
            $body = "";

            if ($isChunked) {
                $bodySize = 0;
                while (1) {
                    while (false === ($lineEndPos = \strpos($buffer, "\r\n"))) {
                        $buffer .= yield;
                    }

                    $line = \substr($buffer, 0, $lineEndPos);
                    $buffer = \substr($buffer, $lineEndPos + 2);
                    $hex = \trim(\ltrim($line, "0")) ?: 0;
                    $chunkLenRemaining = \hexdec($hex);

                    if ($lineEndPos === 0 || $hex != \dechex($chunkLenRemaining)) {
                        ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Bad Request: hex chunk size expected");
                        return;
                    }

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
                                ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Trailer headers too large");
                                return;
                            }
                        } while (!isset($trailers));

                        if (\strpos($trailers, "\n\x20") || \strpos($trailers, "\n\t")) {
                            ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Bad Request: multi-line trailers deprecated by RFC 7230");
                            return;
                        }

                        if (!\preg_match_all(self::HEADER_REGEX, $trailers, $matches)) {
                            ($this->errorEmitter)($client, HTTP_STATUS["BAD_REQUEST"], "Bad Request: trailer syntax violation");
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
                    } elseif ($bodySize + $chunkLenRemaining > $client->streamWindow) {
                        do {
                            $remaining = $client->streamWindow - $bodySize;
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

                            if (!$client->pendingResponses) {
                                return;
                            }

                            if ($bodySize != $client->streamWindow) {
                                continue;
                            }

                            ($this->sizeWarningEmitter)($client);
                            $client->parserEmitLock = true;
                            Loop::disable($client->readWatcher);
                            $yield = yield;
                            if ($yield === false) {
                                $client->shouldClose = true;
                                return;
                            }
                            if (!($client->isDead & Client::CLOSED_RD)) {
                                Loop::enable($client->readWatcher);
                            }
                            $client->parserEmitLock = false;
                        } while ($client->streamWindow < $bodySize + $chunkLenRemaining);
                    }

                    $bodyBufferSize = 0;

                    while (1) {
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
                            continue 2; // next chunk ($is_chunked loop)
                        }
                        $buffer = yield;
                    }
                }

                if ($body != "") {
                    ($this->entityPartEmitter)($client, $body);
                }
            } else {
                $bodySize = 0;
                while (true) {
                    $bound = \min($contentLength, $client->streamWindow);
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
                        $bodySize = $bound;
                    }

                    if ($client->streamWindow < $contentLength) {
                        if (!$client->pendingResponses) {
                            return;
                        }
                        ($this->sizeWarningEmitter)($client);
                        $client->parserEmitLock = true;
                        Loop::disable($client->readWatcher);
                        $yield = yield;
                        if ($yield === false) {
                            $client->shouldClose = true;
                            return;
                        }
                        if ($client->isDead & Client::CLOSED_RD) {
                            Loop::enable($client->readWatcher);
                        }
                        $client->parserEmitLock = false;
                    } else {
                        break;
                    }
                }
            }

            $client->streamWindow = $client->options->maxBodySize;

            ($this->entityResultEmitter)($client);
        } while (true);
    }

    public static function responseInitFilter(InternalRequest $ireq) {
        $headers = yield;
        $status = $headers[":status"];
        $options = $ireq->client->options;

        if ($options->sendServerToken) {
            $headers["server"] = [SERVER_TOKEN];
        }

        if (!empty($headers[":aerys-push"])) {
            foreach ($headers[":aerys-push"] as $url => $pushHeaders) {
                $headers["link"][] = "<$url>; rel=preload";
            }
            unset($headers[":aerys-push"]);
        }

        $contentLength = $headers[":aerys-entity-length"];
        unset($headers[":aerys-entity-length"]);

        if ($contentLength === "@") {
            $hasContent = false;
            $shouldClose = $ireq->protocol === "1.0";
            if (($status >= 200 && $status != 204 && $status != 304)) {
                $headers["content-length"] = ["0"];
            }
        } elseif ($contentLength !== "*") {
            $hasContent = true;
            $shouldClose = $ireq->protocol === "1.0";
            $headers["content-length"] = [$contentLength];
            unset($headers["transfer-encoding"]);
        } elseif ($ireq->protocol === "1.1") {
            $hasContent = true;
            $shouldClose = false;
            $headers["transfer-encoding"] = ["chunked"];
            unset($headers["content-length"]);
        } else {
            $hasContent = true;
            $shouldClose = true;
        }

        if ($hasContent) {
            $type = $headers["content-type"][0] ?? $options->defaultContentType;
            if (\stripos($type, "text/") === 0 && \stripos($type, "charset=") === false) {
                $type .= "; charset={$options->defaultTextCharset}";
            }
            $headers["content-type"] = [$type];
        }

        $remainingRequests = $ireq->client->remainingRequests;
        if ($shouldClose || $remainingRequests <= 0) {
            $headers["connection"] = ["close"];
        } elseif ($remainingRequests < (PHP_INT_MAX >> 1)) {
            $keepAlive = "timeout={$options->connectionTimeout}, max={$remainingRequests}";
            $headers["keep-alive"] = [$keepAlive];
        } else {
            $keepAlive = "timeout={$options->connectionTimeout}";
            $headers["keep-alive"] = [$keepAlive];
        }

        $headers["date"] = [$ireq->httpDate];

        return $headers;
    }

    /**
     * Apply chunk encoding to response entity bodies.
     *
     * @param \Aerys\InternalRequest $ireq
     * @return \Generator
     */
    public static function chunkedResponseFilter(InternalRequest $ireq): \Generator {
        $headers = yield;

        if (empty($headers["transfer-encoding"])) {
            return $headers;
        }
        if (!\in_array("chunked", $headers["transfer-encoding"])) {
            return $headers;
        }

        $bodyBuffer = "";
        $bufferSize = $ireq->client->options->chunkBufferSize ?? 8192;
        $unchunked = yield $headers;

        do {
            $bodyBuffer .= $unchunked;
            if (isset($bodyBuffer[$bufferSize]) || ($unchunked === false && $bodyBuffer != "")) {
                $chunk = \dechex(\strlen($bodyBuffer)) . "\r\n{$bodyBuffer}\r\n";
                $bodyBuffer = "";
            } else {
                $chunk = null;
            }
        } while (($unchunked = yield $chunk) !== null);

        $chunk = ($bodyBuffer != "")
            ? (\dechex(\strlen($bodyBuffer)) . "\r\n{$bodyBuffer}\r\n0\r\n\r\n")
            : "0\r\n\r\n";

        return $chunk;
    }
}
