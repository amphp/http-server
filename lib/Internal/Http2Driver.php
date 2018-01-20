<?php

namespace Aerys\Internal;

// @TODO trailer headers??
// @TODO add ServerObserver for properly sending GOAWAY frames
// @TODO maybe display a real HTML error page for artificial limits exceeded

use Aerys\Body;
use Aerys\ClientException;
use Aerys\NullBody;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Amp\ByteStream\IteratorStream;
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

    /**
     * 64 bit for ping (@TODO we maybe want to timeout once a day and reset the first letter of counter to "a").
     * @var string
     */
    private $counter = "aaaaaaaa";

    /** @var \Aerys\Internal\Client */
    private $client;

    /** @var \Aerys\Options */
    private $options;

    /** @var \Aerys\NullBody */
    private $nullBody;

    /** @var \Aerys\Internal\TimeReference */
    private $timeReference;

    /** @var int */
    private $window = 65536;

    /** @var int */
    private $initialWindowSize = 65536;

    /** @var bool */
    private $allowsPush = true;

    /** @var int Last used stream ID. */
    private $streamId = 0;

    /** @var \Aerys\Internal\Http2Stream[] */
    private $streams = [];

    /** @var int[] */
    private $streamIdMap = [];

    /** @var \Amp\Emitter[] */
    private $bodyEmitters = [];

    /** @var int Number of streams that may be opened. */
    private $remainingStreams;

    /** @var int Number of pending responses. */
    private $pendingResponses = 0;

    /** @var callable */
    private $onMessage;

    /** @var callable */
    private $write;

    /** @var callable */
    private $pause;

    /** @var \Amp\Promise|null */
    private $lastWrite;

    public function __construct(Options $options, TimeReference $timeReference) {
        $this->options = $options;
        $this->nullBody = new NullBody;
        $this->timeReference = $timeReference;

        $this->remainingStreams = $this->options->getMaxConcurrentStreams();
    }

    public function setup(Client $client, callable $onMessage, callable $onError, callable $write, callable $pause) {
        $this->client = $client;
        $this->onMessage = $onMessage;
        // $onError is unused; protocol error responses are not required in HTTP/2, GOAWAY frames are used instead.
        $this->write = $write;
        $this->pause = $pause;
    }

    protected function dispatchInternalRequest(Request $request, int $streamId, string $url, array $pushHeaders = null) {
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
        $authority = $url->getAuthority() ?: $request->getUri()->getAuthority();
        $scheme = $url->getScheme() ?: $request->getUri()->getScheme();
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

        $id = $this->streamId += 2; // Server initiated stream IDs must be even.
        $request = new Request("GET", $url, $headers, $this->nullBody, $path, "2.0");
        $this->streamIdMap[\spl_object_hash($request)] = $id;

        $this->streams[$id] = new Http2Stream;
        $this->streams[$id]->window = $this->initialWindowSize;
        $this->streams[$id]->buffer = "";

        $headers = pack("N", $id) . HPack::encode($headers);
        if (\strlen($headers) >= 16384) {
            $split = str_split($headers, 16384);
            $headers = array_shift($split);
            $this->writeFrame($headers, self::PUSH_PROMISE, self::NOFLAG, $streamId);

            $headers = array_pop($split);
            foreach ($split as $msgPart) {
                $this->writeFrame($msgPart, self::CONTINUATION, self::NOFLAG, $streamId);
            }
            $this->writeFrame($headers, self::CONTINUATION, self::END_HEADERS, $streamId);
        } else {
            $this->writeFrame($headers, self::PUSH_PROMISE, self::END_HEADERS, $streamId);
        }

        $this->pendingResponses++;
        ($this->onMessage)($request);
    }

    public function writer(Response $response, Request $request = null): \Generator {
        \assert($request !== null); // HTTP/2 responses will always have an associated request.

        $hash = \spl_object_hash($request);
        $id = $this->streamIdMap[$hash] ?? 1; // Default ID of 1 for upgrade requests.
        unset($this->streamIdMap[$hash]);

        try {
            $headers = \array_merge([":status" => $response->getStatus()], $response->getHeaders());

            // Remove headers that are obsolete in HTTP/2.
            unset($headers["connection"], $headers["keep-alive"], $headers["transfer-encoding"]);

            $headers["date"] = [$this->timeReference->getCurrentDate()];

            if ($request !== null && !empty($push = $response->getPush())) {
                foreach ($push as $url => $pushHeaders) {
                    if ($this->allowsPush) {
                        $this->dispatchInternalRequest($request, $id, $url, $pushHeaders);
                    } else {
                        $headers["link"][] = "<$url>; rel=preload";
                    }
                }
            }

            $headers = HPack::encode($headers);

            // @TODO decide whether to use max-frame size

            if (\strlen($headers) > 16384) {
                $split = str_split($headers, 16384);
                $headers = array_shift($split);
                $this->writeFrame($headers, self::HEADERS, self::NOFLAG, $id);

                $headers = array_pop($split);
                foreach ($split as $msgPart) {
                    $this->writeFrame($msgPart, self::CONTINUATION, self::NOFLAG, $id);
                }
                $this->writeFrame($headers, self::CONTINUATION, self::END_HEADERS, $id);
            } else {
                $this->writeFrame($headers, self::HEADERS, self::END_HEADERS, $id);
            }

            if ($request !== null && $request->getMethod() === "HEAD") {
                $this->writeData("", $id, true);
                while (null !== yield); // Ignore body portions written.
            } else {
                $buffer = "";

                while (null !== $part = yield) {
                    $buffer .= $part;

                    if (\strlen($buffer) >= $this->options->getOutputBufferSize()) {
                        $this->writeData($buffer, $id, false);
                        $buffer = "";
                    }

                    if ($this->client->getStatus() & Client::CLOSED_WR) {
                        return;
                    }
                }

                $this->writeData($buffer, $id, true);
            }
        } finally {
            if ((!isset($headers) || isset($part)) && !($this->client->getStatus() & Client::CLOSED_WR)) {
                if (($buffer ?? "") !== "") {
                    $this->writeData($buffer, $id, false);
                }

                $this->writeFrame(pack("N", self::INTERNAL_ERROR), self::RST_STREAM, self::NOFLAG, $id);

                unset($this->streams[$id]);

                if (isset($this->bodyEmitters[$id])) {
                    $this->bodyEmitters[$id]->fail(new ClientException);
                    unset($this->bodyEmitters[$id]);
                }
            }

            if ($this->lastWrite) {
                $this->lastWrite->onResolve(function () {
                    $this->remainingStreams++;
                });
            } else {
                $this->remainingStreams++;
            }
        }
    }

    // Note: bufferSize is increased in writeData(), but also here to respect data in streamWindowBuffers;
    // thus needs to be decreased here when shifting data from streamWindowBuffers to writeData()
    protected function writeData(string $data, int $stream, bool $last) {
        $length = \strlen($data);

        if ($this->streams[$stream]->buffer !== "" || $this->window < $length || $this->streams[$stream]->window < $length) {
            $this->streams[$stream]->buffer .= $data;

            if ($last) {
                $this->streams[$stream]->end = true;
            }

            $this->tryDataSend($stream);
            return;
        }

        $this->window -= $length;
        if ($length > 16384) {
            $split = str_split($data, 16384);
            $data = array_pop($split);
            foreach ($split as $part) {
                $this->writeFrame($part, self::DATA, self::NOFLAG, $stream);
            }
        }

        $this->writeFrame($data, self::DATA, $last ? self::END_STREAM : self::NOFLAG, $stream);

        if ($last) {
            $this->pendingResponses--;
            unset($this->streams[$stream]);
            $this->remainingStreams++;
        } else {
            $this->streams[$stream]->window -= $length;
        }
    }

    private function tryDataSend(int $id) {
        $delta = \min($this->window, $this->streams[$id]->window);
        $length = \strlen($this->streams[$id]->buffer);

        if ($length === 0) {
            return;
        }

        if ($delta >= $length) {
            $this->window -= $length;

            if ($length > 16384) {
                $split = str_split($this->streams[$id]->buffer, 16384);
                $this->streams[$id]->buffer = array_pop($split);
                foreach ($split as $part) {
                    $this->writeFrame($part, self::DATA, self::NOFLAG, $id);
                }
            }

            if ($this->streams[$id]->end) {
                $this->writeFrame($this->streams[$id]->buffer, self::DATA, self::END_STREAM, $id);
                $this->pendingResponses--;
                unset($this->streams[$id]);
                $this->remainingStreams++;
            } else {
                $this->writeFrame($this->streams[$id]->buffer, self::DATA, self::NOFLAG, $id);
                $this->streams[$id]->window -= $length;
                $this->streams[$id]->buffer = "";
            }
            return;
        }

        if ($delta > 0) {
            $data = $this->streams[$id]->buffer;
            $end = $delta - 16384;

            for ($off = 0; $off < $end; $off += 16384) {
                $this->writeFrame(substr($data, $off, 16384), self::DATA, self::NOFLAG, $id);
            }

            $this->writeFrame(substr($data, $off, $delta - $off), self::DATA, self::NOFLAG, $id);

            $this->streams[$id]->buffer = substr($data, $delta);
            $this->streams[$id]->window -= $delta;
            $this->window -= $delta;
        }
    }

    protected function writePing() {
        // no need to receive the PONG frame, that's anyway registered by the keep-alive handler
        $data = $this->counter++;
        $this->writeFrame($data, self::PING, self::NOFLAG);
    }

    protected function writeFrame(string $data, string $type, string $flags, int $stream = 0) {
        \assert($stream >= 0);
        $data = substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;

        $this->lastWrite = ($this->write)($data);
    }

    /**
     * @param string $settings HTTP2-Settings header content.
     * @param bool $upgraded True if the connection was upgraded from an HTTP/1.1 upgrade request.
     *
     * @return \Generator
     */
    public function parser(string $settings = "", bool $upgraded = false): \Generator {
        $maxHeaderSize = $this->options->getMaxHeaderSize();
        $maxBodySize = $this->options->getMaxBodySize();
        $maxStreams = $this->options->getMaxConcurrentStreams();
        // $bodyEmitSize = $this->options->ioGranularity; // redundant because data frames, which is 16 KB
        $maxFramesPerSecond = $this->options->getMaxFramesPerSecond();
        $lastReset = 0;
        $framesLastSecond = 0;

        $headers = [];
        $bodyLens = [];
        $table = new HPack;

        if ($this->client->isEncrypted() && ($this->client->getCryptoContext()["alpn_protocol"] ?? null) !== "h2") {
            $error = self::CONNECT_ERROR;
            goto connection_error;
        }

        $setSetting = function (string $buffer) use ($table): int {
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

                    $this->initialWindowSize = $unpacked["value"];
                    return 0;

                case self::ENABLE_PUSH:
                    if ($unpacked["value"] & ~1) {
                        return self::PROTOCOL_ERROR;
                    }

                    $this->allowsPush = (bool) $unpacked["value"];
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
            $this->streams[1] = new Http2Stream;
            $this->streams[1]->window = $this->initialWindowSize;
            $this->remainingStreams--;
        }

        // Initial settings frame.
        $this->writeFrame(
            pack("nNnN", self::INITIAL_WINDOW_SIZE, $maxBodySize + 256, self::MAX_CONCURRENT_STREAMS, $maxStreams),
            self::SETTINGS,
            self::NOFLAG
        );

        $buffer = yield;

        while (\strlen($buffer) < \strlen(self::PREFACE)) {
            $buffer .= yield;
        }

        if (\strncmp($buffer, self::PREFACE, \strlen(self::PREFACE)) !== 0) {
            $error = self::PROTOCOL_ERROR;
            goto connection_error;
        }

        $buffer = \substr($buffer, \strlen(self::PREFACE));
        $this->remainingStreams = $maxStreams;

        try {
            while (true) {
                if (++$framesLastSecond > $maxFramesPerSecond / 2) {
                    $time = $this->timeReference->getCurrentTime();
                    if ($lastReset === $time) {
                        if ($framesLastSecond > $maxFramesPerSecond) {
                            $resume = ($this->pause)(); // aka tiny frame DoS prevention
                            Loop::delay(1000, $resume);
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

                $buffer = \substr($buffer, 9);

                switch ($type) {
                    case self::DATA:
                        if ($id === 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if (!isset($this->bodyEmitters[$id])) {
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

                        if (($remaining = $bodyLens[$id] + $length - ($this->streams[$id]->window ?? $maxBodySize)) > 0) {
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
                            $this->bodyEmitters[$id]->emit($body);
                        }

                        if (($flags & self::END_STREAM) !== "\0") {
                            unset($bodyLens[$id], $this->streams[$id]);
                            $emitter = $this->bodyEmitters[$id];
                            unset($this->bodyEmitters[$id]);
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

                        if ($this->remainingStreams-- <= 0) {
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
                        if ($length !== 4) {
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

                        if (isset($this->bodyEmitters[$id])) {
                            $this->bodyEmitters[$id]->fail(new ClientException);
                            unset($this->bodyEmitters[$id]);
                        }

                        unset(
                            $headers[$id],
                            $bodyLens[$id],
                            $this->streams[$id]
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

                        $this->writeFrame("", self::SETTINGS, self::ACK);
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
                            $this->writeFrame($data, self::PING, self::ACK);
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
                            // @TODO Log error, since the client says we made a boo-boo.
                        }

                        $this->client->close();
                        return;

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
                            if (!isset($this->streams[$id])) {
                                $this->streams[$id] = new Http2Stream;
                                $this->streams[$id]->window = $this->initialWindowSize + $windowSize;
                            } else {
                                $this->streams[$id]->window += $windowSize;
                            }

                            if ($this->streams[$id]->buffer !== "") {
                                $this->tryDataSend($id);
                            }
                        } else {
                            $this->window += $windowSize;
                            foreach ($this->streams as $id => $stream) {
                                $this->tryDataSend($id);
                                if ($this->window == 0) {
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
                    $scheme = $headers[":scheme"][0] ?? ($this->client->isEncrypted() ? "https" : "http");
                    $host = $headers[":authority"][0] ?? "";

                    if ($host === "") {
                        $error = self::PROTOCOL_ERROR;
                        goto connection_error;
                    }

                    if (($colon = \strrpos($host, ":")) !== false) {
                        $port = (int) \substr($host, $colon + 1);
                        $host = \substr($host, 0, $colon);
                    } else {
                        $port = $this->client->getLocalPort();
                    }

                    if ($port) {
                        $uri = new Uri($scheme . "://" . \rawurldecode($host) . ":" . $port . $target);
                    } else {
                        $uri = new Uri($scheme . "://" . \rawurldecode($host) . $target);
                    }

                    if (!isset($this->streams[$id])) {
                        $this->streams[$id] = new Http2Stream;
                        $this->streams[$id]->window = $this->initialWindowSize;
                    }

                    if ($streamEnd) {
                        $request = new Request($headers[":method"][0], $uri, $headers, $this->nullBody, $target, "2.0");
                        $this->streamIdMap[\spl_object_hash($request)] = $id;
                        $this->pendingResponses++;
                        ($this->onMessage)($request);
                    } else {
                        $this->bodyEmitters[$id] = new Emitter;
                        $body = new Body(
                            new IteratorStream($this->bodyEmitters[$id]->iterate()),
                            function (int $bodySize) use ($id) {
                                if (!isset($this->streams[$id], $this->bodyEmitters[$id])
                                    || $bodySize <= $this->streams[$id]->window
                                ) {
                                    return;
                                }

                                $increment = $bodySize - $this->streams[$id]->window;
                                $this->streams[$id]->window = $bodySize;

                                $this->writeFrame(pack("N", $increment), self::WINDOW_UPDATE, self::NOFLAG, $id);
                            }
                        );

                        $request = new Request($headers[":method"][0], $uri, $headers, $body, $target, "2.0");
                        $this->streamIdMap[\spl_object_hash($request)] = $id;
                        $this->pendingResponses++;
                        ($this->onMessage)($request);
                        $bodyLens[$id] = 0;
                    }
                    continue;
                }

                stream_error: {
                    if ($length > (1 << 14)) {
                        $error = self::PROTOCOL_ERROR;
                        goto connection_error;
                    }

                    $this->writeFrame(pack("N", $error), self::RST_STREAM, self::NOFLAG, $id);
                    unset($headers[$id], $bodyLens[$id], $this->streams[$id]);
                    $this->remainingStreams++;

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
            if (!empty($this->bodyEmitters)) {
                $exception = new ClientException("Client disconnected");
                foreach ($this->bodyEmitters as $id => $emitter) {
                    unset($this->bodyEmitters[$id]);
                    $emitter->fail($exception);
                }
            }
        }

        connection_error: {
            $this->writeFrame(pack("NN", 0, $error), self::GOAWAY, self::NOFLAG);
            $this->client->close();
        }
    }

    public function pendingRequestCount(): int {
        return \count($this->bodyEmitters);
    }

    public function pendingResponseCount(): int {
        return $this->pendingResponses;
    }
}
