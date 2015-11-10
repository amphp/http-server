<?php

namespace Aerys;

// @TODO trailer headers??
// @TODO add ServerObserver for properly sending GOAWAY frames
class Http2Driver implements HttpDriver {
    const NOFLAG = "\x00";
    const ACK = "\x01";
    const END_STREAM = "\x01";
    const END_HEADERS = "\x04";
    const PADDED = "\x08";
    const PRIORITY_FLAG = "\x20";

    const DATA = "\x00";
    const HEADERS = "\x01";
    const PRIORITY = "\x02";
    const RST_STREAM = "\x03";
    const SETTINGS = "\x04";
    const PUSH_PROMISE = "\x05";
    const PING = "\x06";
    const GOAWAY = "\x07";
    const WINDOW_UPDATE = "\x08";
    const CONTINUATION = "\x09";

    // Settings
    const HEADER_TABLE_SIZE = 0x1; // 1 << 12
    const ENABLE_PUSH = 0x2; // 1
    const MAX_CONCURRENT_STREAMS = 0x3; // INF
    const INITIAL_WINDOW_SIZE = 0x4; // 1 << 16
    const MAX_FRAME_SIZE = 0x5; // 1 << 14
    const MAX_HEADER_LIST_SIZE = 0x6; // INF

    // Error codes
    const GRACEFUL_SHUTDOWN = 0x0;
    const PROTOCOL_ERROR = 0x1;
    const INTERNAL_ERROR = 0x2;
    const FLOW_CONTROL_ERROR = 0x3;
    const SETTINGS_TIMEOUT = 0x4;
    const STREAM_CLOSED = 0x5;
    const FRAME_SIZE_ERROR = 0x6;
    const REFUSED_STREAM = 0x7;
    const CANCEL = 0x8;
    const COMPRESSION_ERROR = 0x9;
    const CONNECT_ERROR = 0xa;
    const ENHANCE_YOUR_CALM = 0xb;
    const INADEQUATE_SECURITY = 0xc;
    const HTTP_1_1_REQUIRED = 0xd;

    private $options;
    private $counter = "aaaaaaaa"; // 64 bit for ping (@TODO we maybe want to timeout once a day and reset the first letter of counter to "a")

    private $emit;
    private $write;

    public function __construct(Options $options, callable $emit, callable $write) {
        $this->options = $options;

        $this->emit = $emit;
        $this->write = $write;
    }

    public function versions(): array {
        return ["2.0"];
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
        if ($ireq->method === "HEAD") {
            $filters[] = '\Aerys\nullBodyResponseFilter';
        }
        return $filters;
    }

    public function responseInitFilter(InternalRequest $ireq) {
        if (isset($ireq->headers[":authority"])) {
            $ireq->headers["host"] = $ireq->headers[":authority"];
        }
        unset($ireq->headers[":authority"], $ireq->headers[":scheme"], $ireq->headers[":method"], $ireq->headers[":path"]);

        $options = $ireq->client->options;
        $headers = yield;

        if ($options->sendServerToken) {
            $headers["server"] = [SERVER_TOKEN];
        }

        if (!empty($headers[":aerys-push"])) {
            foreach ($headers[":aerys-push"] as $url => $pushHeaders) {
                if ($ireq->client->allowsPush) {
                    $this->dispatchInternalRequest($ireq, $url, $pushHeaders);
                } else {
                    $headers["link"][] = "<$url>; rel=preload";
                }
            }
            unset($headers[":aerys-push"]);
        }

        $status = $headers[":status"];
        $contentLength = $headers[":aerys-entity-length"];
        unset($headers[":aerys-entity-length"]);
        unset($headers["transfer-encoding"]); // obsolete in HTTP/2

        if ($contentLength === "@") {
            $hasContent = false;
            if (($status >= 200 && $status != 204 && $status != 304)) {
                $headers["content-length"] = ["0"];
            }
        } elseif ($contentLength !== "*") {
            $hasContent = true;
            $headers["content-length"] = [$contentLength];
        } else {
            $hasContent = true;
            unset($headers["content-length"]);
        }

        if ($hasContent) {
            $type = $headers["content-type"][0] ?? $options->defaultContentType;
            if (\stripos($type, "text/") === 0 && \stripos($type, "charset=") === false) {
                $type .= "; charset={$options->defaultTextCharset}";
            }
            $headers["content-type"] = [$type];
        }

        $headers["date"] = [$ireq->httpDate];

        return $headers;
    }

    private function dispatchInternalRequest(InternalRequest $ireq, string $url, array $pushHeaders = null) {
        $client = $ireq->client;
        $id = $client->serverStreamId += 2;

        if ($pushHeaders === null) {
            // headers to take over from original request if present
            $pushHeaders = array_intersect_key($ireq->headers, [
                "accept" => 1,
                "accept-charset" => 1,
                "accept-encoding" => 1,
                "accept-language" => 1,
                "authorization" => 1,
                "cookie" => 1,
                "date" => 1,
                "transfer-encoding" => 1,
                "user-agent" => 1,
                "via" => 1,
            ]);
            $pushHeaders["referer"] = $ireq->uri;
        }

        $headerArray = $headerList = [];

        $url = parse_url($url);
        $scheme = $url["scheme"] ?? ($ireq->isEncrypted ? "https" : "http");
        $authority = $url["host"] ?? $ireq->uriHost;
        if (isset($url["port"])) {
            $authority .= ":" . $url["port"];
        } elseif (isset($ireq->uriPort)) {
            $authority .= ":" . $ireq->uriPort;
        }
        $path = $url["path"] . ($url["query"] ?? "");

        $headerArray[":authority"][0] = $authority;
        $headerList[] = [":authority", $authority];
        $headerArray[":scheme"][0] = $scheme;
        $headerList[] = [":scheme", $scheme];
        $headerArray[":path"][0] = $path;
        $headerList[] = [":path", $path];
        $headerArray[":method"][0] = "GET";
        $headerList[] = [":method", "GET"];

        foreach (array_change_key_case($pushHeaders, CASE_LOWER) as $name => $header) {
            if (\is_int($name)) {
                \assert(\is_array($header));
                $headerList[] = $header;
                list($name, $header) = $header;
                $headerArray[$name][] = $header;
            } elseif (\is_string($header)) {
                $headerList[] = [$name, $header];
                $headerArray[$name][] = $header;
            } else {
                \assert(\is_array($header));
                foreach ($header as $value) {
                    $headerList[] = [$name, $value];
                }
                $headerArray[$name] = $header;
            }
        }

        $parseResult = [
            "id" => $id,
            "trace" => $headerList,
            "protocol" => "2.0",
            "method" => "GET",
            "uri" => $authority ? "$scheme://$authority$path" : $path,
            "headers" => $headerArray,
            "body" => "",
            "server_push" => $ireq->uri,
        ];

        $client->streamWindow[$id] = $client->initialWindowSize;
        $client->streamWindowBuffer[$id] = "";

        $headers = pack("N", $id) . HPack::encode($headerArray);
        if (\strlen($headers) >= 16384) {
            $split = str_split($headers, 16384);
            $headers = array_shift($split);
            $this->writeFrame($client, $headers, self::PUSH_PROMISE, self::NOFLAG, $ireq->streamId);

            $headers = array_pop($split);
            foreach ($split as $msgPart) {
                $this->writeFrame($client, $msgPart, self::CONTINUATION, self::NOFLAG, $ireq->streamId);
            }
            $this->writeFrame($client, $headers, self::CONTINUATION, self::END_HEADERS, $ireq->streamId);
        } else {
            $this->writeFrame($client, $headers, self::PUSH_PROMISE, self::END_HEADERS, $ireq->streamId);
        }

        ($this->emit)([HttpDriver::RESULT, $parseResult, null], $client);
    }

    public function writer(InternalRequest $ireq): \Generator {
        $client = $ireq->client;
        $id = $ireq->streamId;

        $headers = yield;
        unset($headers[":reason"], $headers["connection"]); // obsolete in HTTP/2.0
        $headers = HPack::encode($headers);

        $lastPart = yield;

        // @TODO decide whether to use max-frame size

        if (\strlen($headers) >= 16384) {
            $split = str_split($headers, 16384);
            $headers = array_shift($split);
            $this->writeFrame($client, $headers, self::HEADERS, $lastPart === null ? self::END_STREAM : self::NOFLAG, $id);

            $headers = array_pop($split);
            foreach ($split as $msgPart) {
                $this->writeFrame($client, $msgPart, self::CONTINUATION, self::NOFLAG, $id);
            }
            $this->writeFrame($client, $headers, self::CONTINUATION, self::END_HEADERS, $id);

            if ($lastPart === null) {
                return;
            }
        } elseif ($lastPart === null) {
            $this->writeFrame($client, $headers, self::HEADERS, self::END_HEADERS | self::END_STREAM, $id);
            return;
        } else {
            $this->writeFrame($client, $headers, self::HEADERS, self::END_HEADERS, $id);
        }

        while (($msgPart = yield) !== null) {
            if ($client->isDead || !isset($client->streamWindow[$id])) {
                throw new ClientException;
            }

            if ($msgPart == false) {
                continue;
            }

            if (\strlen($lastPart) >= 16384) {
                foreach (str_split($lastPart, 16384) as $lastPart) {
                    $this->writeData($client, $lastPart, $id, false);
                }
            } else {
                $this->writeData($client, $lastPart, $id, false);
            }
            $lastPart = $msgPart;
        }

        if (\strlen($lastPart) >= 16384) {
            $split = str_split($lastPart, 16384);
            $lastPart = array_pop($split);
            foreach ($split as $msgPart) {
                $this->writeData($client, $msgPart, $id, false);
            }
        }

        $this->writeData($client, $lastPart, $id);
    }

    public function writeData(Client $client, $data, $stream, $last = true) {
        $len = \strlen($data);
        if ($client->window < $len || $client->streamWindow[$stream] < $len) {
            $client->streamWindowBuffer[$stream] .= $data; // @TODO limit maximum buffer size
            if ($last) {
                $client->streamEnd[$stream] = true;
            }
            $this->tryDataSend($client, $stream);
        } else {
            $client->window -= $len;
            if ($last) {
                $this->writeFrame($client, $data, self::DATA, $last ? self::END_STREAM : self::NOFLAG, $stream);
                unset($client->streamWindow[$stream], $client->streamWindowBuffer[$stream]);
            } else {
                $client->streamWindow[$stream] -= $len;
                $this->writeFrame($client, $data, self::DATA, $last ? self::END_STREAM : self::NOFLAG, $stream);
            }
        }
    }

    private function tryDataSend(Client $client, $id) {
        $delta = min($client->window, $client->streamWindow[$id]);
        if ($delta > \strlen($client->streamWindowBuffer[$id])) {
            $client->window -= \strlen($client->streamWindowBuffer[$id]);
            if (isset($client->streamEnd[$id])) {
                $this->writeFrame($client, $client->streamWindowBuffer[$id], self::DATA, self::END_STREAM, $id);
                unset($client->streamWindowBuffer[$id], $client->streamEnd[$id], $client->streamWindow[$id]);
            } else {
                $this->writeFrame($client, $client->streamWindowBuffer[$id], self::DATA, self::NOFLAG, $id);
                $client->streamWindow[$id] -= \strlen($client->streamWindowBuffer[$id]);
                $client->streamWindowBuffer[$id] = "";
            }
        } elseif ($delta > 0) {
            $this->writeFrame($client, substr($client->streamWindowBuffer[$id], 0, $delta), self::DATA, self::NOFLAG, $id);
            $client->streamWindowBuffer[$id] = substr($client->streamWindowBuffer[$id], $delta);
            $client->streamWindow[$id] -= $delta;
            $client->window -= $delta;
        }
    }

    public function writePing(Client $client) {
        $data = $this->counter++;
        // @TODO store ping; add timeout
        $this->writeFrame($client, $data, self::PING, self::NOFLAG);
    }

    private function writeFrame(Client $client, $data, $type, $flags, $stream = 0) {
        assert($stream >= 0);
assert(!\defined("Aerys\\DEBUG_HTTP2") || print "OUT: ");
assert(!\defined("Aerys\\DEBUG_HTTP2") || !(unset) var_dump(bin2hex(substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data)));
        $client->writeBuffer .= substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;
        ($this->write)($client, $type == self::DATA && ($flags & self::END_STREAM) != "\0");
    }

    public function parser(Client $client): \Generator {
        // @TODO apply restrictions
        $maxHeaderSize = $this->options->maxHeaderSize;
        $maxPendingSize = $this->options->maxPendingSize;
        $maxBodySize = $this->options->maxBodySize;
        // $bodyEmitSize = $this->options->ioGranularity; // redundant because data frames (?)

assert(!\defined("Aerys\\DEBUG_HTTP2") || print "INIT\n");

        $this->writeFrame($client, pack("nN", self::INITIAL_WINDOW_SIZE, $maxBodySize), self::SETTINGS, self::NOFLAG);
        $this->writeFrame($client, "\x7f\xfe\xff\xff", self::WINDOW_UPDATE, self::NOFLAG); // effectively disabling flow control...

        $headers = [];
        $table = new HPack;

        $buffer = yield;

        while (1) {
            while (\strlen($buffer) < 9) {
                $buffer .= yield;
            }
            $length = unpack("N", "\0$buffer")[1];
            // @TODO SETTINGS: MAX_FRAME_SIZE
            $type = $buffer[3];
            $flags = $buffer[4];
            $id = unpack("N", substr($buffer, 5, 4))[1];
            // the highest bit must be zero... but RFC does not specify what should happen when it is set to 1?
            if ($id < 0) {
                $id = ~$id;
            }
assert(!\defined("Aerys\\DEBUG_HTTP2") || print "Flag: ".bin2hex($flags)."; Type: ".bin2hex($type)."; Stream: $id; Length: $length\n");
            $buffer = substr($buffer, 9);

            switch ($type) {
                case self::DATA:
                    if ($id === 0) {
                        $error = self::PROTOCOL_ERROR;
                        break 2;
                    }

                    if (!isset($client->bodyPromisors[$id])) {
                        if (isset($headers[$id])) {
                            $error = self::PROTOCOL_ERROR;
                            break 2;
                        } else {
                            $error = self::STREAM_CLOSED;
                            while (\strlen($buffer) < $length) {
                                $buffer .= yield;
                            }
                            $buffer = substr($buffer, $length);
                            goto stream_error;
                        }
                    }

                    if (($flags & self::PADDED) != "\0") {
                        if ($buffer === "") {
                            $buffer = yield;
                        }
                        $padding = ord($buffer[0]);
                        $buffer = substr($buffer, 1);
                        $length--;

                        if ($padding >= $length) {
                            $error = self::PROTOCOL_ERROR;
                            break 2;
                        }
                    } else {
                        $padding = 0;
                    }

                    while (\strlen($buffer) < $length) {
                        $buffer .= yield;
                    }

                    if (($flags & self::END_STREAM) != "\0") {
                        $type = HttpDriver::ENTITY_RESULT;
                    } else {
                        $type = HttpDriver::ENTITY_PART;
                    }

assert(!\defined("Aerys\\DEBUG_HTTP2") || print "DATA($length): ".substr($buffer, 0, $length - $padding)."\n");
                    ($this->emit)([$type, ["id" => $id, "protocol" => "2.0", "body" => substr($buffer, 0, $length - $padding)], null], $client);
                    $buffer = substr($buffer, $length);

                    continue 2;

                case self::HEADERS:
                    if (($flags & self::PADDED) != "\0") {
                        if ($buffer == "") {
                            $buffer = yield;
                        }
                        $padding = ord($buffer[0]);
                        $buffer = substr($buffer, 1);
                        $length--;

                        if ($padding >= $length) {
                            $error = self::PROTOCOL_ERROR;
                            break 2;
                        }
                    } else {
                        $padding = 0;
                    }

                    if (($flags & self::PRIORITY_FLAG) != "\0") {
                        while (\strlen($buffer) < 5) {
                            $buffer .= yield;
                        }
                        /* Not yet needed?!
                        $dependency = unpack("N", $buffer)[1];
                        if ($dependency < 0) {
                            $dependency = ~$dependency;
                            $exclusive = true;
                        } else {
                            $exclusive = false;
                        }
                        $weight = $buffer[4];
                        */
                        $buffer = substr($buffer, 5);
                        $length -= 5;
                    }

                    while (\strlen($buffer) < $length) {
                        $buffer .= yield;
                    }

                    $packed = substr($buffer, 0, $length);
                    $buffer = substr($buffer, $length);

                    $streamEnd = ($flags & self::END_STREAM) != "\0";
                    if (($flags & self::END_HEADERS) != "\0") {
                        goto parse_headers;
                    } else {
                        $headers[$id] = $packed;
                    }

                    continue 2;

                case self::PRIORITY:
                    if ($length != 5) {
                        $error = self::FRAME_SIZE_ERROR;
                        while (\strlen($buffer) < $length) {
                            $buffer .= yield;
                        }
                        $buffer = substr($buffer, $length);
                        goto stream_error;
                    }

                    if ($id === 0) {
                        $error = self::PROTOCOL_ERROR;
                        break 2;
                    }

                    while (\strlen($buffer) < 5) {
                        $buffer .= yield;
                    }

                    /* @TODO PRIORITY frames not yet handled?!
                    $dependency = unpack("N", $buffer);
                    if ($dependency < 0) {
                        $dependency = ~$dependency;
                        $exclusive = true;
                    } else {
                        $exclusive = false;
                    }
                    if ($dependency == 0) {
                        $error = self::PROTOCOL_ERROR;
                        break 2;
                    }
                    $weight = $buffer[4];
                    */

                    $buffer = substr($buffer, 5);
assert(!defined("Aerys\\DEBUG_HTTP2") || print "PRIORITY: - \n");
                    continue 2;

                case self::RST_STREAM:
                    if ($length != 4) {
                        $error = self::FRAME_SIZE_ERROR;
                        break 2;
                    }

                    if ($id === 0) {
                        $error = self::PROTOCOL_ERROR;
                        break 2;
                    }

                    while (\strlen($buffer) < 4) {
                        $buffer .= yield;
                    }

                    $error = unpack("N", $buffer)[1];

assert(!defined("Aerys\\DEBUG_HTTP2") || print "RST_STREAM: $error\n");
                    if (isset($client->bodyPromisors[$id])) {
                        $client->bodyPromisors[$id]->fail(new ClientException);
                    }
                    unset($headers[$id], $client->streamWindow[$id], $client->streamEnd[$id], $client->streamWindowBuffer[$id], $client->bodyPromisors[$id]);

                    $buffer = substr($buffer, 4);
                    continue 2;

                case self::SETTINGS:
                    if ($id !== 0) {
                        $error = self::PROTOCOL_ERROR;
                        break 2;
                    }

                    if (($flags & self::ACK) != "\0") {
                        if ($length) {
                            $error = self::PROTOCOL_ERROR;
                            break 2;
                        }

assert(!defined("Aerys\\DEBUG_HTTP2") || print "SETTINGS: ACK\n");
                        // got ACK
                        continue 2;
                    } elseif ($length % 6 != 0) {
                        $error = self::FRAME_SIZE_ERROR;
                        break 2;
                    }

                    while ($length > 0) {
                        while (\strlen($buffer) < 6) {
                            $buffer .= yield;
                        }

                        $unpacked = unpack("nsetting/Nvalue", $buffer); // $unpacked["value" >= 0
assert(!defined("Aerys\\DEBUG_HTTP2") || print "SETTINGS({$unpacked["setting"]}): {$unpacked["value"]}\n");

                        switch ($unpacked["setting"]) {
                            case self::MAX_HEADER_LIST_SIZE:
                                if ($unpacked["value"] >= 4096) {
                                    $error = self::PROTOCOL_ERROR; // @TODO correct error??
                                    break 4;
                                }
                                $table->table_resize($unpacked["value"]);
                                break;

                            case self::INITIAL_WINDOW_SIZE:
                                if ($unpacked["value"] >= 1 << 31) {
                                    $error = self::FLOW_CONTROL_ERROR;
                                    break 4;
                                }
                                $client->initialWindowSize = $unpacked["value"];
                                break;

                            case self::ENABLE_PUSH:
                                if ($unpacked["value"] & ~1) {
                                    $error = self::PROTOCOL_ERROR;
                                    break 4;
                                }
                                $client->allowsPush = (bool) $unpacked["value"];
                                break;
                        }

                        $buffer = substr($buffer, 6);
                        $length -= 6;
                    }

                    $this->writeFrame($client, "", self::SETTINGS, self::ACK);
                    continue 2;

                // PUSH_PROMISE sent by client is a PROTOCOL_ERROR (just like undefined frame types)

                case self::PING:
                    if ($length != 8) {
                        $error = self::FRAME_SIZE_ERROR;
                        break 2;
                    }

                    if ($id !== 0) {
                        $error = self::PROTOCOL_ERROR;
                        break 2;
                    }

                    while (\strlen($buffer) < 8) {
                        $buffer .= yield;
                    }

                    $data = substr($buffer, 0, 8);

                    if ($flags & self::ACK) {
                        // @TODO resolve ping
                    } else {
                        $this->writeFrame($client, $data, self::PING, self::ACK);
                    }

                    $buffer = substr($buffer, 8);

                    continue 2;

                case self::GOAWAY:
                    if ($id !== 0) {
                        $error = self::PROTOCOL_ERROR;
                        break 2;
                    }

                    $lastId = unpack("N", $buffer)[1];
                    // the highest bit must be zero... but RFC does not specify what should happen when it is set to 1?
                    if ($lastId < 0) {
                        $lastId = ~$lastId;
                    }
                    $error = unpack("N", substr($buffer, 4, 4))[1];

                    $buffer = substr($buffer, 8);
                    $length -= 8;

                    while (\strlen($buffer) < $length) {
                        $buffer .= yield;
                    }

                    if ($error !== 0) {
                        // ($this->emit)([HttpDriver::ERROR, ["body" => substr($buffer, 0, $length)], $error], $client);
                    }

assert(!defined("Aerys\\DEBUG_HTTP2") || print "GOAWAY($error): ".substr($buffer, 0, $length)."\n");
                    return; // @TODO verify whether it needs to be manually closed

                case self::WINDOW_UPDATE:
                    while (\strlen($buffer) < 4) {
                        $buffer .= yield;
                    }

                    if ($buffer === "\0\0\0\0") {
                        $error = self::PROTOCOL_ERROR;
                        if ($id) {
                            goto stream_error;
                        } else {
                            break 2;
                        }
                    }

                    $windowSize = unpack("N", $buffer)[1];
                    if ($id) {
                        if (!isset($client->streamWindow[$id])) {
                            $client->streamWindow[$id] = $client->initialWindowSize + $windowSize;
                        } else {
                            $client->streamWindow[$id] += $windowSize;
                        }
                        if (isset($client->streamWindowBuffer[$id])) {
                            $this->tryDataSend($client, $id);
                        }
                    } else {
                        $client->window += $windowSize;
                        foreach ($client->streamWindowBuffer as $stream => $data) {
                            $this->tryDataSend($client, $stream);
                            if ($client->window == 0) {
                                break;
                            }
                        }
                    }

                    $buffer = substr($buffer, 4);

                    continue 2;

                case self::CONTINUATION:
                    if (!isset($headers[$id])) {
                        $error = self::PROTOCOL_ERROR;
                        break 2;
                    }

                    while (\strlen($buffer) < $length) {
                        $buffer .= yield;
                    }

                    $headers[$id] .= substr($buffer, 0, $length);
                    $buffer = substr($buffer, $length);

                    if (($flags & self::END_HEADERS) != "\0") {
                        $packed = $headers[$id];
                        unset($headers[$id]);
                        goto parse_headers;
                    }

                    continue 2;

                default:
                    print "BAD TYPE: ".ord($type)."\n";
                    $error = self::PROTOCOL_ERROR;
                    break 2;
            }

parse_headers:
            $headerList = $table->decode($padding ? substr($packed, 0, -$padding) : $packed);
            if ($headerList === null) {
                $error = self::COMPRESSION_ERROR;
                break;
            }
assert(!defined("Aerys\\DEBUG_HTTP2") || print "HEADER(".(\strlen($packed) - $padding)."): ".implode(" | ", array_map(function($x){return implode(": ", $x);},$headerList))."\n");

            $headerArray = [];
            foreach ($headerList as list($name, $value)) {
                $headerArray[$name][] = $value;
            }

            $parseResult = [
                "id" => $id,
                "trace" => $headerList,
                "protocol" => "2.0",
                "method" => $headerArray[":method"][0],
                "uri" => !empty($headerArray[":authority"][0]) ? "{$headerArray[":scheme"][0]}://{$headerArray[":authority"][0]}{$headerArray[":path"][0]}" : $headerArray[":path"][0],
                "headers" => $headerArray,
                "body" => "",
            ];

            if (!isset($client->streamWindow[$id])) {
                $client->streamWindow[$id] = $client->initialWindowSize;
            }
            $client->streamWindowBuffer[$id] = "";

            if ($streamEnd) {
                ($this->emit)([HttpDriver::RESULT, $parseResult, null], $client);
            } else {
                ($this->emit)([HttpDriver::ENTITY_HEADERS, $parseResult, null], $client);
            }
            continue;

stream_error:
            $this->writeFrame($client, pack("N", $error), self::RST_STREAM, self::NOFLAG, $id);
assert(!defined("Aerys\\DEBUG_HTTP2") || print "Stream ERROR: $error\n");
            continue;
        }

        $this->writeFrame($client, pack("NN", 0, $error), self::GOAWAY, self::NOFLAG);
assert(!defined("Aerys\\DEBUG_HTTP2") || print "Connection ERROR: $error\n");
    }
}