<?php

namespace Aerys;

class Http1Driver implements HttpDriver {
    const HEADER_REGEX = "(
        ([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    )x";

    private $options;

    private $emit;
    private $write;

    public function __construct(Options $options, callable $emit, callable $write) {
        $this->options = $options;

        $this->emit = $emit;
        $this->write = $write;
    }

    public function versions(): array {
        return ["1.0", "1.1"];
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

        // @TODO change the protocol upgrade mechanism ... it's garbage as currently implemented
        if ($client->shouldClose || $headers[":status"] !== "101") {
            $client->onUpgrade = null;
        } else {
            $client->onUpgrade = $headers[":on-upgrade"] ?? null;
        }

        $lines = ["HTTP/{$protocol} {$headers[":status"]} {$headers[":reason"]}"];
        unset($headers[":status"], $headers[":reason"]);
        foreach ($headers as $headerField => $headerLines) {
            if ($headerField[0] !== ":") {
                foreach ($headerLines as $headerLine) {
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

            if (($msgPart === false || $bufferSize > $this->options->outputBufferSize)) {
                $client->writeBuffer .= \implode("", $buffer);
                $buffer = [];
                $bufferSize = 0;
                ($this->write)($client);
            }
        } while (($msgPart = yield) !== null);

        if ($bufferSize) {
            $client->writeBuffer .= \implode("", $buffer);
        }

        ($this->write)($client, $final = true);
    }


    public function parser($callbackData): \Generator {
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

            while (1) {
                $yield = yield;
                if ($yield === "") {
                    break;
                }

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

            if (!$hasBody) {
                $parseResult["unparsed"] = $buffer;
                if ($method == "PRI") {
                    ($this->emit)([Server::PARSE["UPGRADE"], $parseResult, null], $callbackData);
                    return;
                } else {
                    ($this->emit)([Server::PARSE["RESULT"], $parseResult, null], $callbackData);
                    continue;
                }
            }

            ($this->emit)([Server::PARSE["ENTITY_HEADERS"], $parseResult, null], $callbackData);
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
                                ($this->emit)([Server::PARSE["ENTITY_PART"], ["body" => $body], null], $callbackData);
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
                        ($this->emit)([Server::PARSE["ENTITY_PART"], ["body" => $buffer], null], $callbackData);
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
                ($this->emit)([Server::PARSE["ENTITY_PART"], ["body" => $body], null], $callbackData);
            }

            $parseResult["unparsed"] = $buffer;
            ($this->emit)([Server::PARSE["ENTITY_RESULT"], $parseResult, null], $callbackData);
        }

        // An error occurred...
        // stop parsing here ...
        ($this->emit)([Server::PARSE["ERROR"], $parseResult, $error], $callbackData);
        while (1) {
            yield;
        }
    }
}