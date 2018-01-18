<?php

namespace Aerys\Internal;

// @TODO trailer headers??
// @TODO add ServerObserver for properly sending GOAWAY frames
// @TODO maybe display a real HTML error page for artificial limits exceeded

use Aerys\Body;
use Aerys\ClientException;
use Aerys\HttpStatus;
use Aerys\NullBody;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Amp\ByteStream\IteratorStream;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Loop;
use Amp\Uri\Uri;

class Http2Driver implements HttpDriver {
    const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

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

    private $counter = "aaaaaaaa"; // 64 bit for ping (@TODO we maybe want to timeout once a day and reset the first letter of counter to "a")

    /** @var callable */
    private $onRequest;

    /** @var callable */
    private $onError;

    /** @var callable */
    private $write;

    /** @var \Aerys\NullBody */
    private $nullBody;

    /** @var int */
    private $time;

    /** @var string */
    private $date;

    public function __construct() {
        $this->nullBody = new NullBody;
    }

    public function setup(Server $server, callable $onRequest, callable $onError, callable $write) {
        $server->onTimeUpdate(function (int $time, string $date) {
            $this->time = $time;
            $this->date = $date;
        });

        $this->onRequest = $onRequest;
        $this->onError = $onError;
        $this->write = $write;
    }

    private function filter(Client $client, Response $response, Request $request = null): array {
        $headers = [":status" => $response->getStatus()];
        $headers = \array_merge($headers, $response->getHeaders());

        if ($request !== null && !empty($push = $response->getPush())) {
            foreach ($push as $url => $pushHeaders) {
                if ($client->allowsPush) {
                    $this->dispatchInternalRequest($client, $request, $url, $pushHeaders);
                } else {
                    $headers["link"][] = "<$url>; rel=preload";
                }
            }
        }

        $headers["date"] = [$this->date];

        return $headers;
    }

    public function dispatchInternalRequest(Client $client, Request $request, string $url, array $pushHeaders = null) {
        $id = $client->streamId += 2;

        if ($pushHeaders === null) {
            // headers to take over from original request if present
            $pushHeaders = array_intersect_key($request->getHeaders(), [
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
            $pushHeaders["referer"] = (string) $request->getUri();
        }

        $headers = [];

        $url = new Uri($url);
        $host = $url->getHost() ?: $request->getUri()->getHost();
        $port = $url->getPort() ?: $request->getUri()->getPort();
        $authority = $authority = \rawurlencode($host) . ":" . $port;
        $scheme = $url->getScheme() ?: ($client->isEncrypted ? "https" : "http");
        $path = $url->getPath();

        if ($query = $url->getQuery()) {
            $path .= "?" . $query;
        }

        $headers[":authority"][0] = $authority;
        $headers[":scheme"][0] = $scheme;
        $headers[":path"][0] = $path;
        $headers[":method"][0] = "GET";

        foreach (\array_change_key_case($pushHeaders, \CASE_LOWER) as $name => $header) {
            if (\is_int($name)) {
                \assert(\is_array($header));
                list($name, $header) = $header;
                $headers[$name][] = $header;
            } elseif (\is_string($header)) {
                $headers[$name][] = $header;
            } else {
                \assert(\is_array($header));
                $headers[$name] = $header;
            }
        }

        $request = new Request("GET", $url, $headers, $this->nullBody, $path, "2.0", $id);

        $client->streamWindow[$id] = $client->initialWindowSize;
        $client->streamWindowBuffer[$id] = "";

        $headers = pack("N", $id) . HPack::encode($headers);
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

        ($this->onRequest)($client, $request);
    }

    public function writer(Client $client, Response $response, Request $request = null): \Generator {
        $id = $request !== null ? $request->getStreamId() : 0;

        try {
            $headers = $this->filter($client, $response, $request);
            unset($headers["connection"]); // obsolete in HTTP/2.0
            $headers = HPack::encode($headers);

            // @TODO decide whether to use max-frame size

            if (\strlen($headers) > 16384) {
                $split = str_split($headers, 16384);
                $headers = array_shift($split);
                $this->writeFrame($client, $headers, self::HEADERS, self::NOFLAG, $id);

                $headers = array_pop($split);
                foreach ($split as $msgPart) {
                    $this->writeFrame($client, $msgPart, self::CONTINUATION, self::NOFLAG, $id);
                }
                $this->writeFrame($client, $headers, self::CONTINUATION, self::END_HEADERS, $id);
            } else {
                $this->writeFrame($client, $headers, self::HEADERS, self::END_HEADERS, $id);
            }

            if ($request !== null && $request->getMethod() === "HEAD") {
                $this->writeData($client, "", $id, true);
                while (null !== yield); // Ignore body portions written.
            } else {
                $buffer = "";

                while (null !== $part = yield) {
                    $buffer .= $part;

                    if (\strlen($buffer) >= $client->options->getOutputBufferSize()) {
                        $this->writeData($client, $buffer, $id, false);
                        $buffer = "";
                    }

                    if ($client->isDead & Client::CLOSED_WR) {
                        return;
                    }
                }

                $this->writeData($client, $buffer, $id, true);
            }
        } finally {
            if ((!isset($headers) || isset($part)) && !($client->isDead & Client::CLOSED_WR)) {
                if (($buffer ?? "") !== "") {
                    $this->writeData($client, $buffer, $id, false);
                }

                $this->writeFrame($client, pack("N", self::INTERNAL_ERROR), self::RST_STREAM, self::NOFLAG, $id);

                if (isset($client->bodyEmitters[$id])) {
                    $client->bodyEmitters[$id]->fail(new ClientException);
                    unset($client->bodyEmitters[$id]);
                }
            }

            if ($client->bufferDeferred) {
                $keepAlives = &$client->remainingRequests; // avoid cyclic reference
                $client->bufferDeferred->onResolve(static function () use (&$keepAlives) {
                    $keepAlives++;
                });
            } else {
                $client->remainingRequests++;
            }
        }
    }

    // Note: bufferSize is increased in writeData(), but also here to respect data in streamWindowBuffers;
    // thus needs to be decreased here when shifting data from streamWindowBuffers to writeData()
    public function writeData(Client $client, string $data, int $stream, bool $last) {
        $len = \strlen($data);
        if ($client->streamWindowBuffer[$stream] !== "" || $client->window < $len || $client->streamWindow[$stream] < $len) {
            $client->streamWindowBuffer[$stream] .= $data;
            if ($last) {
                $client->streamEnd[$stream] = true;
            }
            $client->bufferSize += $len;
            if (!$client->bufferDeferred && $client->bufferSize > $client->options->getSoftStreamCap()) {
                $client->bufferDeferred = new Deferred;
            }
            $this->tryDataSend($client, $stream);
        } else {
            $client->window -= $len;
            if ($len > 16384) {
                $split = str_split($data, 16384);
                $data = array_pop($split);
                foreach ($split as $part) {
                    $this->writeFrame($client, $part, self::DATA, self::NOFLAG, $stream);
                }
            }
            if ($last) {
                $this->writeFrame($client, $data, self::DATA, $last ? self::END_STREAM : self::NOFLAG, $stream);
                unset($client->streamWindow[$stream], $client->streamWindowBuffer[$stream]);
            } else {
                $client->streamWindow[$stream] -= $len;
                $this->writeFrame($client, $data, self::DATA, $last ? self::END_STREAM : self::NOFLAG, $stream);
            }
        }
    }

    private function tryDataSend(Client $client, int $id) {
        $delta = min($client->window, $client->streamWindow[$id]);
        $len = \strlen($client->streamWindowBuffer[$id]);
        if ($delta >= $len) {
            $client->window -= $len;
            $client->bufferSize -= $len;
            if ($len > 16384) {
                $split = str_split($client->streamWindowBuffer[$id], 16384);
                $client->streamWindowBuffer[$id] = array_pop($split);
                foreach ($split as $part) {
                    $this->writeFrame($client, $part, self::DATA, self::NOFLAG, $id);
                }
            }
            if (isset($client->streamEnd[$id])) {
                $this->writeFrame($client, $client->streamWindowBuffer[$id], self::DATA, self::END_STREAM, $id);
                unset($client->streamWindowBuffer[$id], $client->streamEnd[$id], $client->streamWindow[$id]);
            } else {
                $this->writeFrame($client, $client->streamWindowBuffer[$id], self::DATA, self::NOFLAG, $id);
                $client->streamWindow[$id] -= $len;
                $client->streamWindowBuffer[$id] = "";
            }
        } elseif ($delta > 0) {
            $data = $client->streamWindowBuffer[$id];
            $end = $delta - 16384;
            $client->bufferSize -= $delta;
            for ($off = 0; $off < $end; $off += 16384) {
                $this->writeFrame($client, substr($data, $off, 16384), self::DATA, self::NOFLAG, $id);
            }
            $this->writeFrame($client, substr($data, $off, $delta - $off), self::DATA, self::NOFLAG, $id);
            $client->streamWindowBuffer[$id] = substr($data, $delta);
            $client->streamWindow[$id] -= $delta;
            $client->window -= $delta;
        }
    }

    public function writePing(Client $client) {
        // no need to receive the PONG frame, that's anyway registered by the keep-alive handler
        $data = $this->counter++;
        $this->writeFrame($client, $data, self::PING, self::NOFLAG);
    }

    protected function writeFrame(Client $client, string $data, string $type, string $flags, int $stream = 0) {
        \assert($stream >= 0);
        $data = substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;
        ($this->write)($client, $data, $type == self::DATA && ($flags & self::END_STREAM) !== "\0");
    }

    /**
     * @param \Aerys\Internal\Client $client
     * @param string $settings HTTP2-Settings header content.
     * @param bool $upgraded True if the connection was upgraded from an HTTP/1.1 upgrade request.
     *
     * @return \Generator
     */
    public function parser(Client $client, string $settings = "", bool $upgraded = false): \Generator {
        $maxHeaderSize = $client->options->getMaxHeaderSize();
        $maxBodySize = $client->options->getMaxBodySize();
        $maxStreams = $client->options->getMaxConcurrentStreams();
        // $bodyEmitSize = $client->options->ioGranularity; // redundant because data frames, which is 16 KB
        $maxFramesPerSecond = $client->options->getMaxFramesPerSecond();
        $lastReset = 0;
        $framesLastSecond = 0;

        $headers = [];
        $bodyLens = [];
        $table = new HPack;

        $setSetting = function (string $buffer) use ($client, $table): int {
            $unpacked = \unpack("nsetting/Nvalue", $buffer); // $unpacked["value"] >= 0

            switch ($unpacked["setting"]) {
                case self::MAX_HEADER_LIST_SIZE:
                    if ($unpacked["value"] >= 4096) {
                        return self::PROTOCOL_ERROR; // @TODO correct error??
                    }

                    $table->table_resize($unpacked["value"]);
                    return 0;

                case self::INITIAL_WINDOW_SIZE:
                    if ($unpacked["value"] >= 1 << 31) {
                        return self::FLOW_CONTROL_ERROR;
                    }

                    $client->initialWindowSize = $unpacked["value"];
                    return 0;

                case self::ENABLE_PUSH:
                    if ($unpacked["value"] & ~1) {
                        return self::PROTOCOL_ERROR;
                    }

                    $client->allowsPush = (bool) $unpacked["value"];
                    return 0;

                default:
                    return 0; // Unused setting
            }
        };

        if ($settings !== "") {
            if (\strlen($settings) % 6 !== 0) {
                $error = self::FRAME_SIZE_ERROR;
                goto connection_error;
            }

            do {
                if ($error = $setSetting($settings)) {
                    goto connection_error;
                }
                $settings = substr($settings, 6);
            } while ($settings !== "");
        }

        if ($upgraded) {
            // Upgraded connections automatically assume an initial stream with ID 1.
            $client->streamWindow[1] = $client->initialWindowSize;
            $client->streamWindowBuffer[1] = "";
        }

        $this->writeFrame($client, pack("nNnN", self::INITIAL_WINDOW_SIZE, $maxBodySize + 256, self::MAX_CONCURRENT_STREAMS, $maxStreams), self::SETTINGS, self::NOFLAG);
        $this->writeFrame($client, "\x7f\xfe\xff\xff", self::WINDOW_UPDATE, self::NOFLAG); // effectively disabling global flow control...

        $buffer = yield;

        while (\strlen($buffer) < \strlen(self::PREFACE)) {
            $buffer .= yield;
        }
        if (\strncmp($buffer, self::PREFACE, \strlen(self::PREFACE)) !== 0) {
            $start = \strpos($buffer, "HTTP/") + 5;
            if ($start < \strlen($buffer)) {
                $protocol = \substr($buffer, $start, \strpos($buffer, "\r\n", $start) - $start);
                ($this->onError)($client, HttpStatus::HTTP_VERSION_NOT_SUPPORTED, "Unsupported version {$protocol}");
            }
            return;
        }
        $buffer = \substr($buffer, \strlen(self::PREFACE));
        $client->remainingRequests = $maxStreams;

        try {
            while (true) {
                if (++$framesLastSecond > $maxFramesPerSecond / 2) {
                    $time = $this->time;
                    if ($lastReset === $time) {
                        if ($framesLastSecond > $maxFramesPerSecond) {
                            Loop::disable($client->readWatcher); // aka tiny frame DoS prevention
                            Loop::delay(1000, static function (string $watcher, Client $client) {
                                if (!($client->isDead & Client::CLOSED_RD)) {
                                    Loop::enable($client->readWatcher);
                                    $client->requestParser->next();
                                }
                            }, $client);
                            yield;
                            $framesLastSecond = 0;
                        }
                    } else {
                        $framesLastSecond = 0;
                    }
                    $lastReset = $time;
                }


                while (\strlen($buffer) < 9) {
                    $buffer .= yield;
                }

                $length = \unpack("N", "\0$buffer")[1];
                // @TODO SETTINGS: MAX_FRAME_SIZE
                $type = $buffer[3];
                $flags = $buffer[4];
                $id = \unpack("N", \substr($buffer, 5, 4))[1];

                // the highest bit must be zero... but RFC does not specify what should happen when it is set to 1?
                /*if ($id < 0) {
                    $id = ~$id;
                }*/

                // For debugging flag and type values.
                \assert(function () use ($flags, $type) {
                    $flags = bin2hex($flags);
                    $type = \bin2hex($type);

                    return true;
                });

                $buffer = \substr($buffer, 9);

                switch ($type) {
                    case self::DATA:
                        if ($id === 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if (!isset($client->bodyEmitters[$id])) {
                            if (isset($headers[$id])) {
                                $error = self::PROTOCOL_ERROR;
                                goto connection_error;
                            }

                            // Technically it is a protocol error to send data to a never opened stream
                            // but we do not want to store what streams WE have closed via RST_STREAM,
                            // thus we're just reporting them as closed
                            $error = self::STREAM_CLOSED;
                            goto stream_error;
                        }

                        if (($flags & self::PADDED) !== "\0") {
                            if ($buffer === "") {
                                $buffer = yield;
                            }
                            $padding = \ord($buffer);
                            $buffer = \substr($buffer, 1);
                            $length--;

                            if ($padding > $length) {
                                $error = self::PROTOCOL_ERROR;
                                goto connection_error;
                            }
                        } else {
                            $padding = 0;
                        }

                        if ($length > (1 << 14)) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if (($remaining = $bodyLens[$id] + $length - ($client->streamWindow[$id] ?? $maxBodySize)) > 0) {
                            $error = self::FLOW_CONTROL_ERROR;
                            goto connection_error;
                        }

                        while (\strlen($buffer) < $length) {
                            /* it is fine to just .= the $body as $length < 2^14 */
                            $buffer .= yield;
                        }

                        $body = \substr($buffer, 0, $length - $padding);
                        $buffer = \substr($buffer, $length);
                        if ($body !== "") {
                            $client->bodyEmitters[$id]->emit($body);
                        }

                        if (($flags & self::END_STREAM) !== "\0") {
                            unset($bodyLens[$id], $client->streamWindow[$id]);
                            $emitter = $client->bodyEmitters[$id];
                            unset($client->bodyEmitters[$id]);
                            $emitter->complete();
                        } else {
                            $bodyLens[$id] += $length;

                            if ($remaining == 0 && $length) {
                                $error = self::ENHANCE_YOUR_CALM;
                                goto connection_error;
                            }
                        }

                        continue 2;

                    case self::HEADERS:
                        if (isset($headers[$id])) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if ($client->remainingRequests-- <= 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if (($flags & self::PADDED) !== "\0") {
                            if ($buffer == "") {
                                $buffer = yield;
                            }
                            $padding = \ord($buffer);
                            $buffer = \substr($buffer, 1);
                            $length--;
                        } else {
                            $padding = 0;
                        }

                        if (($flags & self::PRIORITY_FLAG) !== "\0") {
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
                            $buffer = \substr($buffer, 5);
                            $length -= 5;
                        }

                        if ($padding >= $length) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if ($length > $maxHeaderSize) {
                            $error = self::ENHANCE_YOUR_CALM;
                            goto stream_error;
                        }

                        while (\strlen($buffer) < $length) {
                            $buffer .= yield;
                        }

                        $packed = \substr($buffer, 0, $length - $padding);
                        $buffer = \substr($buffer, $length);

                        $streamEnd = ($flags & self::END_STREAM) !== "\0";
                        if (($flags & self::END_HEADERS) !== "\0") {
                            goto parse_headers;
                        }

                        $headers[$id] = $packed;

                        continue 2;

                    case self::PRIORITY:
                        if ($length != 5) {
                            $error = self::FRAME_SIZE_ERROR;
                            goto connection_error;
                        }

                        if ($id === 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        while (\strlen($buffer) < 5) {
                            $buffer .= yield;
                        }

                        /* @TODO PRIORITY frames not yet handled?!
                         * $dependency = unpack("N", $buffer);
                         * if ($dependency < 0) {
                         * $dependency = ~$dependency;
                         * $exclusive = true;
                         * } else {
                         * $exclusive = false;
                         * }
                         * if ($dependency == 0) {
                         * $error = self::PROTOCOL_ERROR;
                         * goto connection_error;
                         * }
                         * $weight = $buffer[4];
                         */

                        $buffer = \substr($buffer, 5);
                        continue 2;

                    case self::RST_STREAM:
                        if ($length != 4) {
                            $error = self::FRAME_SIZE_ERROR;
                            goto connection_error;
                        }

                        if ($id === 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        while (\strlen($buffer) < 4) {
                            $buffer .= yield;
                        }

                        $error = \unpack("N", $buffer)[1];

                        if (isset($client->bodyEmitters[$id])) {
                            $client->bodyEmitters[$id]->fail(new ClientException);
                            unset($client->bodyEmitters[$id]);
                        }

                        unset(
                            $headers[$id],
                            $bodyLens[$id],
                            $client->streamWindow[$id],
                            $client->streamEnd[$id],
                            $client->streamWindowBuffer[$id]
                        );

                        $buffer = \substr($buffer, 4);
                        continue 2;

                    case self::SETTINGS:
                        if ($id !== 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if (($flags & self::ACK) !== "\0") {
                            if ($length) {
                                $error = self::PROTOCOL_ERROR;
                                goto connection_error;
                            }

                            // Got ACK
                            continue 2;
                        }

                        if ($length % 6 != 0) {
                            $error = self::FRAME_SIZE_ERROR;
                            goto connection_error;
                        }

                        if ($length > 60) {
                            // Even with room for a few future options, sending that a big SETTINGS frame is just about wasting our processing time. I hereby declare this a protocol error.
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        while ($length > 0) {
                            while (\strlen($buffer) < 6) {
                                $buffer .= yield;
                            }

                            if ($error = $setSetting($buffer)) {
                                goto connection_error;
                            }

                            $buffer = \substr($buffer, 6);
                            $length -= 6;
                        }

                        $this->writeFrame($client, "", self::SETTINGS, self::ACK);
                        continue 2;

                    // PUSH_PROMISE sent by client is a PROTOCOL_ERROR (just like undefined frame types)

                    case self::PING:
                        if ($length != 8) {
                            $error = self::FRAME_SIZE_ERROR;
                            goto connection_error;
                        }

                        if ($id !== 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        while (\strlen($buffer) < 8) {
                            $buffer .= yield;
                        }

                        $data = \substr($buffer, 0, 8);

                        if (($flags & self::ACK) !== "\0") {
                            // do not resolve ping - unneeded because of connectionTimeout
                        } else {
                            $this->writeFrame($client, $data, self::PING, self::ACK);
                        }

                        $buffer = \substr($buffer, 8);

                        continue 2;

                    case self::GOAWAY:
                        if ($id !== 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        $lastId = \unpack("N", $buffer)[1];
                        // the highest bit must be zero... but RFC does not specify what should happen when it is set to 1?
                        if ($lastId < 0) {
                            $lastId = ~$lastId;
                        }
                        $error = \unpack("N", substr($buffer, 4, 4))[1];

                        $buffer = \substr($buffer, 8);
                        $length -= 8;

                        while (\strlen($buffer) < $length) {
                            $buffer .= yield;
                        }

                        if ($error !== 0) {
                            ($this->onError)($client, HttpStatus::BAD_REQUEST, $error);
                            return;
                        }

                        $client->shouldClose = true;
                        Loop::disable($client->readWatcher);
                        return;
                        // connectionTimeout will force a close when necessary

                    case self::WINDOW_UPDATE:
                        if ($length != 4) {
                            $error = self::FRAME_SIZE_ERROR;
                            goto connection_error;
                        }

                        while (\strlen($buffer) < 4) {
                            $buffer .= yield;
                        }

                        if ($buffer === "\0\0\0\0") {
                            $error = self::PROTOCOL_ERROR;
                            if ($id) {
                                goto stream_error;
                            }
                            goto connection_error;
                        }

                        $windowSize = \unpack("N", $buffer)[1];
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

                        $buffer = \substr($buffer, 4);

                        continue 2;

                    case self::CONTINUATION:
                        if (!isset($headers[$id])) {
                            // technically it is a protocol error to send data to a never opened stream
                            // but we do not want to store what streams WE have closed via RST_STREAM,
                            // thus we're just reporting them as closed
                            $error = self::STREAM_CLOSED;
                            goto stream_error;
                        }

                        if ($length > $maxHeaderSize - \strlen($headers[$id])) {
                            $error = self::ENHANCE_YOUR_CALM;
                            goto stream_error;
                        }

                        while (\strlen($buffer) < $length) {
                            $buffer .= yield;
                        }

                        $headers[$id] .= \substr($buffer, 0, $length);
                        $buffer = \substr($buffer, $length);

                        if (($flags & self::END_HEADERS) !== "\0") {
                            $packed = $headers[$id];
                            unset($headers[$id]);
                            goto parse_headers;
                        }

                        continue 2;

                    default:
                        $error = self::PROTOCOL_ERROR;
                        goto connection_error;
                }

                parse_headers: {
                    $decoded = $table->decode($packed);
                    if ($decoded === null) {
                        $error = self::COMPRESSION_ERROR;
                        break;
                    }

                    $headers = [];
                    foreach ($decoded as list($name, $value)) {
                        $headers[$name][] = $value;
                    }

                    $target = $headers[":path"][0];
                    $scheme = $headers[":scheme"][0] ?? ($client->isEncrypted ? "https" : "http");
                    $host = $headers[":authority"][0] ?? "";

                    if ($host === "") {
                        $error = self::PROTOCOL_ERROR;
                        goto connection_error;
                    }

                    if (($colon = \strrpos($host, ":")) !== false) {
                        $port = (int) \substr($host, $colon + 1);
                        $host = \substr($host, 0, $colon);
                    } else {
                        $port = $client->serverPort;
                    }

                    $uri = new Uri($scheme . "://" . \rawurldecode($host) . ":" . $port . $target);

                    if (!isset($client->streamWindow[$id])) {
                        $client->streamWindow[$id] = $client->initialWindowSize;
                    }
                    $client->streamWindowBuffer[$id] = "";

                    if ($streamEnd) {
                        $request = new Request($headers[":method"][0], $uri, $headers, $this->nullBody, $target, "2.0", $id);
                        ($this->onRequest)($client, $request);
                    } else {
                        $client->bodyEmitters[$id] = new Emitter;
                        $body = new Body(
                            new IteratorStream($client->bodyEmitters[$id]->iterate()),
                            function (int $bodySize) use ($id, $client) {
                                if (!isset($client->streamWindow[$id], $client->bodyEmitters[$id])
                                    || $bodySize <= $client->streamWindow[$id]
                                ) {
                                    return;
                                }

                                $increment = $bodySize - $client->streamWindow[$id];
                                $client->streamWindow[$id] = $bodySize;

                                $this->writeFrame($client, pack("N", $increment), self::WINDOW_UPDATE, self::NOFLAG, $id);
                            }
                        );

                        $request = new Request($headers[":method"][0], $uri, $headers, $body, $target, "2.0", $id);

                        ($this->onRequest)($client, $request);
                        $bodyLens[$id] = 0;
                    }
                    continue;
                }

                stream_error: {
                    if ($length > (1 << 14)) {
                        $error = self::PROTOCOL_ERROR;
                        goto connection_error;
                    }

                    $this->writeFrame($client, pack("N", $error), self::RST_STREAM, self::NOFLAG, $id);
                    unset($headers[$id], $bodyLens[$id], $client->streamWindow[$id]);
                    $client->remainingRequests++;

                    // consume whole frame to be able to continue this connection
                    $length -= \strlen($buffer);
                    while ($length > 0) {
                        $buffer = yield;
                        $length -= \strlen($buffer);
                    }
                    $buffer = substr($buffer, \strlen($buffer) + $length);
                    continue;
                }
            }
        } finally {
            if (!empty($client->bodyEmitters)) {
                $exception = new ClientException("Client disconnected");
                foreach ($client->bodyEmitters as $id => $emitter) {
                    unset($client->bodyEmitters[$id]);
                    $emitter->fail($exception);
                }
            }
        }

        connection_error: {
            $client->shouldClose = true;
            $this->writeFrame($client, pack("NN", 0, $error), self::GOAWAY, self::NOFLAG);

            Loop::disable($client->readWatcher);
        }
    }
}
