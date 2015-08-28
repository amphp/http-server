<?php

namespace Aerys;

class Http1Driver implements HttpDriver {
    const HEADER_REGEX = "(
        ([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    )x";

    private $options;
    private $parseEmitter;
    private $responseWriter;
    private $h2cUpgradeFilter;

    public function __construct(Options $options, callable $parseEmitter, callable $responseWriter) {
        $this->options = $options;
        $this->parseEmitter = $parseEmitter;
        $this->responseWriter = $responseWriter;
        $this->h2cUpgradeFilter = function(InternalRequest $ireq) {
            $ireq->responseWriter->send([
                ":status" => HTTP_STATUS["SWITCHING_PROTOCOLS"],
                ":reason" => "Switching Protocols",
                "connection" => ["Upgrade"],
                "upgrade" => ["h2c"],
            ]);
            $ireq->responseWriter->send(false); // flush before replacing

            // @TODO inject this into the current h1 driver instance when instantiated
            $httpDriver = $this->h2Driver;
            $ireq->client->httpDriver = $httpDriver;
            $ireq->responseWriter = $httpDriver->writer($ireq);
        };
    }

    public function versions(): array {
        return ["1.0", "1.1"];
    }

    public function filters(InternalRequest $ireq): array {
        $filters = [
            [$this, "responseInitFilter"],
            '\Aerys\genericResponseFilter',
        ];
        if ($userFilters = $ireq->vhost->getFilters()) {
            $filters = array_merge($filters, array_values($userFilters));
        }
        if ($this->options->deflateEnable) {
            $filters[] = '\Aerys\deflateResponseFilter';
        }
        if ($ireq->protocol === "1.1") {
            $filters[] = [$this, "chunkedResponseFilter"];
        }
        if ($ireq->method === "HEAD") {
            $filters[] = '\Aerys\nullBodyResponseFilter';
        }
        if ($ireq->protocol === "1.1" &&
            isset($ireq->headers["upgrade"][0]) &&
            $ireq->headers["upgrade"][0] === "h2c"
        ) {
            $filters[] = $this->h2cUpgradeFilter;
        }

        return $filters;
    }

    public function writer(InternalRequest $ireq): \Generator {
        $headers = yield;

        $client = $ireq->client;
        $protocol = $ireq->protocol;

        if (!empty($headers["connection"])) {
            foreach ($headers["connection"] as $connection) {
                if (\strcasecmp($connection, "close") === 0) {
                    $client->shouldClose = true;
                }
            }
        }

        $lines = ["HTTP/{$protocol} {$headers[":status"]} {$headers[":reason"]}"];
        unset($headers[":status"], $headers[":reason"]);
        foreach ($headers as $headerField => $headerLines) {
            if ($headerField[0] !== ":") {
                foreach ($headerLines as $headerLine) {
                    /* verify header fields (per RFC) and header values against containing \n */
                    \assert(strpbrk($headerField, "\n\t ()<>@,;:\\\"/[]?={}") === false && strpbrk($headerLine, "\n") === false);
                    $lines[] = "{$headerField}: {$headerLine}";
                }
            }
        }
        $lines[] = "\r\n";
        $msgPart = \implode("\r\n", $lines);
        $bufferSize = 0;

        do {
            if ($client->isDead) {
                throw new ClientException;
            }

            $buffer[] = $msgPart;
            $bufferSize += \strlen($msgPart);

            if (($msgPart === false || $bufferSize > $ireq->options->outputBufferSize)) {
                $client->writeBuffer .= \implode("", $buffer);
                $buffer = [];
                $bufferSize = 0;
                ($this->responseWriter)($client);
            }
        } while (($msgPart = yield) !== null);

        if ($bufferSize) {
            $client->writeBuffer .= \implode("", $buffer);
        }

        ($this->responseWriter)($client, $final = true);
    }

    public function parser(Client $client): \Generator {
        $maxHeaderSize = $this->options->maxHeaderSize;
        $maxPendingSize = $this->options->maxPendingSize;
        $maxBodySize = $this->options->maxBodySize;
        $bodyEmitSize = $this->options->ioGranularity;

        $buffer = "";

        while (1) {
            // break potential references
            unset($traceBuffer, $protocol, $method, $uri, $headers);

            $traceBuffer = null;
            $headers = [];
            $contentLength = null;
            $isChunked = false;
            $protocol = null;
            $uri = null;
            $method = null;

            $parseResult = [
                "id" => 0, // dummy-id
                "trace" => &$traceBuffer,
                "protocol" => &$protocol,
                "method" => &$method,
                "uri" => &$uri,
                "headers" => &$headers,
                "body" => "",
            ];

            while ($client->parserEmitLock) {
                $yield = yield;

                $buffer .= $yield;
                if (\strlen($buffer) > $maxPendingSize) {
                    // @TODO check if handling is not too primitive… hmm…
                    while (1) {
                        yield;
                    }
                }
            }

            while (1) {
                $buffer = ltrim($buffer, "\r\n");

                if ($headerPos = strpos($buffer, "\r\n\r\n")) {
                    $startLineAndHeaders = substr($buffer, 0, $headerPos + 2);
                    $buffer = (string)substr($buffer, $headerPos + 4);
                    break;
                } elseif ($maxHeaderSize > 0 && strlen($buffer) > $maxHeaderSize) {
                    $error = "Bad Request: header size violation";
                    break 2;
                }

                $buffer .= yield;
            }

            $startLineEndPos = strpos($startLineAndHeaders, "\n");
            $startLine = rtrim(substr($startLineAndHeaders, 0, $startLineEndPos), "\r\n");
            $rawHeaders = substr($startLineAndHeaders, $startLineEndPos + 1);
            $traceBuffer = $startLineAndHeaders;

            if (!$method = strtok($startLine, " ")) {
                $error = "Bad Request: invalid request line";
                break;
            }

            if (!$uri = strtok(" ")) {
                $error = "Bad Request: invalid request line";
                break;
            }

            $protocol = strtok(" ");
            if (stripos($protocol, "HTTP/") !== 0) {
                $error = "Bad Request: invalid request line";
                break;
            }

            $protocol = substr($protocol, 5);

            if ($rawHeaders) {
                if (strpos($rawHeaders, "\n\x20") || strpos($rawHeaders, "\n\t")) {
                    $error = "Bad Request: multi-line headers deprecated by RFC 7230";
                    break;
                }

                if (!preg_match_all(self::HEADER_REGEX, $rawHeaders, $matches)) {
                    $error = "Bad Request: header syntax violation";
                    break;
                }

                list(, $fields, $values) = $matches;

                $headers = [];
                foreach ($fields as $index => $field) {
                    $headers[$field][] = $values[$index];
                }

                if ($headers) {
                    $headers = array_change_key_case($headers);
                }

                $contentLength = $headers["content-length"][0] ?? null;

                if (isset($headers["transfer-encoding"])) {
                    $value = $headers["transfer-encoding"][0];
                    $isChunked = (bool) strcasecmp($value, "identity");
                }

                // @TODO validate that the bytes in matched headers match the raw input. If not there is a syntax error.
            }

            if ($contentLength > $maxBodySize) {
                $error = "Bad request: entity too large";
                break;
            } elseif (($method == "HEAD" || $method == "TRACE" || $method == "OPTIONS") || $contentLength === 0) {
                // No body allowed for these messages
                $hasBody = false;
            } else {
                $hasBody = $isChunked || $contentLength;
            }

            $client->parserEmitLock = true;

            if (!$hasBody) {
                $parseResult["unparsed"] = $buffer;
                if ($method == "PRI") {
                    ($this->parseEmitter)([HttpDriver::UPGRADE, $parseResult, null], $client);
                    return;
                } else {
                    ($this->parseEmitter)([HttpDriver::RESULT, $parseResult, null], $client);
                    continue;
                }
            }

            ($this->parseEmitter)([HttpDriver::ENTITY_HEADERS, $parseResult, null], $client);
            $body = "";

            if ($isChunked) {
                while (1) {
                    while (false === ($lineEndPos = strpos($buffer, "\r\n"))) {
                        $buffer .= yield;
                    }

                    $line = substr($buffer, 0, $lineEndPos);
                    $buffer = substr($buffer, $lineEndPos + 2);
                    $hex = trim(ltrim($line, "0")) ?: 0;
                    $chunkLenRemaining = hexdec($hex);

                    if ($lineEndPos === 0 || $hex != dechex($chunkLenRemaining)) {
                        $error = "Bad Request: hex chunk size expected";
                        break 2;
                    }

                    if ($chunkLenRemaining === 0) {
                        while (!isset($buffer[1])) {
                            $buffer .= yield;
                        }
                        $firstTwoBytes = substr($buffer, 0, 2);
                        if ($firstTwoBytes === "\r\n") {
                            $buffer = substr($buffer, 2);
                            break; // finished ($is_chunked loop)
                        }

                        do {
                            if ($trailerSize = strpos($buffer, "\r\n\r\n")) {
                                $trailers = substr($buffer, 0, $trailerSize + 2);
                                $buffer = substr($buffer, $trailerSize + 4);
                            } else {
                                $buffer .= yield;
                                $trailerSize = \strlen($buffer);
                                $trailers = null;
                            }
                            if ($maxHeaderSize > 0 && $trailerSize > $maxHeaderSize) {
                                $error = "Trailer headers too large";
                                break 3;
                            }
                        } while (!isset($trailers));

                        if (strpos($trailers, "\n\x20") || strpos($trailers, "\n\t")) {
                            $error = "Bad Request: multi-line trailers deprecated by RFC 7230";
                            break 2;
                        }

                        if (!preg_match_all(self::HEADER_REGEX, $trailers, $matches)) {
                            $error = "Bad Request: trailer syntax violation";
                            break 2;
                        }

                        list(, $fields, $values) = $matches;
                        $trailers = [];
                        foreach ($fields as $index => $field) {
                            $trailers[$field][] = $values[$index];
                        }

                        if ($trailers) {
                            $trailers = array_change_key_case($trailers, CASE_UPPER);

                            foreach (["transfer-encoding", "content-length", "trailer"] as $remove) {
                                unset($trailers[$remove]);
                            }

                            if ($trailers) {
                                $headers = array_merge($headers, $trailers);
                            }
                        }

                        break; // finished ($is_chunked loop)
                    } elseif ($chunkLenRemaining > $maxBodySize) {
                        $error = "Bad Request: excessive chunk size";
                        break 2;
                    } else {
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
                                ($this->parseEmitter)([HttpDriver::ENTITY_PART, ["id" => 0, "body" => $body], null], $client);
                                $body = '';
                                $bodyBufferSize = 0;
                            }

                            if ($bufferLen >= $chunkLenRemaining + 2) {
                                $chunkLenRemaining = null;
                                continue 2; // next chunk ($is_chunked loop)
                            } else {
                                $buffer = yield;
                            }
                        }
                    }
                }
            } else {
                $bufferDataSize = \strlen($buffer);

                while ($bufferDataSize < $contentLength) {
                    if ($bufferDataSize >= $bodyEmitSize) {
                        ($this->parseEmitter)([HttpDriver::ENTITY_PART, ["id" => 0, "body" => $buffer], null], $client);
                        $buffer = "";
                        $contentLength -= $bufferDataSize;
                    }
                    $buffer .= yield;
                    $bufferDataSize = \strlen($buffer);
                }

                if ($bufferDataSize === $contentLength) {
                    $body = $buffer;
                    $buffer = "";
                } else {
                    $body = substr($buffer, 0, $contentLength);
                    $buffer = (string)substr($buffer, $contentLength);
                }
            }

            if ($body != "") {
                ($this->parseEmitter)([HttpDriver::ENTITY_PART, ["id" => 0, "body" => $body], null], $client);
            }

            $parseResult["unparsed"] = $buffer;
            ($this->parseEmitter)([HttpDriver::ENTITY_RESULT, $parseResult, null], $client);
        }

        // An error occurred...
        // stop parsing here ...
        ($this->parseEmitter)([HttpDriver::ERROR, $parseResult, $error], $client);
        while (1) {
            yield;
        }
    }

    public static function responseInitFilter(InternalRequest $ireq) {
        $headers = yield;
        $status = $headers[":status"];

        if ($ireq->options->sendServerToken) {
            $headers["server"] = [SERVER_TOKEN];
        }

        $contentLength = $headers[":aerys-entity-length"];
        unset($headers[":aerys-entity-length"]);

        if ($contentLength === "@") {
            $hasContent = false;
            $shouldClose = ($ireq->protocol === "1.0");
            if (($status >= 200 && $status != 204 && $status != 304)) {
                $headers["content-length"] = ["0"];
            }
        } elseif ($contentLength !== "*") {
            $hasContent = true;
            $shouldClose = false;
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
            $type = $headers["content-type"][0] ?? $ireq->options->defaultContentType;
            if (\stripos($type, "text/") === 0 && \stripos($type, "charset=") === false) {
                $type .= "; charset={$ireq->options->defaultTextCharset}";
            }
            $headers["content-type"] = [$type];
        }

        $remainingKeepAlives = $ireq->client->remainingKeepAlives;
        if ($shouldClose || $ireq->isServerStopping || $remainingKeepAlives === 0) {
            $headers["connection"] = ["close"];
        } elseif (isset($remainingKeepAlives)) {
            $keepAlive = "timeout={$ireq->options->keepAliveTimeout}, max={$remainingKeepAlives}";
            $headers["keep-alive"] = [$keepAlive];
        } else {
            $keepAlive = "timeout={$ireq->options->keepAliveTimeout}";
            $headers["keep-alive"] = [$keepAlive];
        }

        $headers["date"] = [$ireq->httpDate];

        return $headers;
    }

    /**
     * Apply chunk encoding to response entity bodies
     *
     * @param \Aerys\InternalRequest $ireq
     * @return \Generator
     */
    public static function chunkedResponseFilter(InternalRequest $ireq): \Generator {
        $headers = yield;

        if (empty($headers["transfer-encoding"])) {
            return;
        }
        if (!in_array("chunked", $headers["transfer-encoding"])) {
            return;
        }

        $bodyBuffer = "";
        $bufferSize = $ireq->options->chunkBufferSize ?? 8192;
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
            : "0\r\n\r\n"
        ;

        return $chunk;
    }
}
