<?php

namespace Aerys;

// @TODO add ServerObserver for properly sending GOAWAY frames

use Aerys\Internal\Http2Stream;
use Amp\ByteStream\IteratorStream;
use Amp\Deferred;
use Amp\Delayed;
use Amp\Emitter;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Uri\InvalidUriException;
use Amp\Uri\Uri;

class Http2Driver implements HttpDriver {
    const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    const DEFAULT_MAX_FRAME_SIZE = 1 << 14;
    const DEFAULT_WINDOW_SIZE = 1 << 16;

    const HEADER_NAME_REGEX = '/^[\x21-\x40\x5b-\x7e]+$/';

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

    // Headers to take over from original request if present
    const PUSH_PROMISE_INTERSECT = [
        "accept" => 1,
        "accept-charset" => 1,
        "accept-encoding" => 1,
        "accept-language" => 1,
        "authorization" => 1,
        "cache-control" => 1,
        "cookie" => 1,
        "date" => 1,
        "host" => 1,
        "user-agent" => 1,
        "via" => 1,
    ];

    /**
     * 64 bit for ping (@TODO we maybe want to timeout once a day and reset the first letter of counter to "a").
     * @var string
     */
    private $counter = "aaaaaaaa";

    /** @var \Aerys\Client */
    private $client;

    /** @var \Aerys\Options */
    private $options;

    /** @var \Aerys\TimeReference */
    private $timeReference;

    /** @var int */
    private $window = self::DEFAULT_WINDOW_SIZE;

    /** @var int */
    private $initialWindowSize = self::DEFAULT_WINDOW_SIZE;

    /** @var int */
    private $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE;

    /** @var bool */
    private $allowsPush = true;

    /** @var int Last used stream ID. */
    private $streamId = 0;

    /** @var \Aerys\Internal\Http2Stream[] */
    private $streams = [];

    /** @var int[] */
    private $streamIdMap = [];

    /** @var \Amp\Deferred[] */
    private $trailerDeferreds = [];

    /** @var \Amp\Emitter[] */
    private $bodyEmitters = [];

    /** @var int Number of streams that may be opened. */
    private $remainingStreams;

    /** @var callable */
    private $onMessage;

    /** @var callable */
    private $write;

    /** @var \Aerys\Internal\HPack */
    private $table;

    public function __construct(Options $options, TimeReference $timeReference) {
        $this->options = $options;
        $this->timeReference = $timeReference;

        $this->remainingStreams = $this->options->getMaxConcurrentStreams();

        $this->table = new Internal\HPack;
    }

    /**
     * @param \Aerys\Client $client
     * @param callable $onMessage
     * @param callable $write
     * @param string|null $settings HTTP2-Settings header content from upgrade request or null for direct HTTP/2.
     *
     * @return \Generator
     */
    public function setup(Client $client, callable $onMessage, callable $write, string $settings = null): \Generator {
        \assert(!$this->client, "The driver has already been setup");

        $this->client = $client;
        $this->onMessage = $onMessage;
        $this->write = $write;

        return $this->parser($settings);
    }

    protected function dispatchInternalRequest(Request $request, int $streamId, string $url, array $headers = []) {
        $uri = $request->getUri();

        if (!\preg_match("#^https?://#i", $url)) {
            $url = $uri->getScheme() . "://" . $uri->getAuthority(false) . "/" . \ltrim($url, "/");
        }

        $url = new Uri($url);

        $path = $url->getPath();
        if ($query = $url->getQuery()) {
            $path .= "?" . $query;
        }

        $headers = \array_merge([
            ":authority" => [$url->getAuthority(false)],
            ":scheme" => [$url->getScheme()],
            ":path" => [$path],
            ":method" => ["GET"],
        ], \array_intersect_key($request->getHeaders(), self::PUSH_PROMISE_INTERSECT), $headers);

        $id = $this->streamId += 2; // Server initiated stream IDs must be even.
        $request = new Request($this->client, "GET", $url, $headers, null, "2.0");
        $this->streamIdMap[\spl_object_hash($request)] = $id;

        $this->streams[$id] = new Http2Stream(
            $this->initialWindowSize,
            Http2Stream::RESERVED | Http2Stream::REMOTE_CLOSED
        );

        $headers = pack("N", $id) . Internal\HPack::encode($headers);
        if (\strlen($headers) >= $this->maxFrameSize) {
            $split = str_split($headers, $this->maxFrameSize);
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

        ($this->onMessage)($request);
    }

    public function writer(Response $response, Request $request = null): \Generator {
        \assert($this->client, "The driver has not been setup");
        \assert($request !== null); // HTTP/2 responses will always have an associated request.

        $hash = \spl_object_hash($request);
        $id = $this->streamIdMap[$hash] ?? 1; // Default ID of 1 for upgrade requests.
        unset($this->streamIdMap[$hash]);

        if (!isset($this->streams[$id])) {
            return;
        }

        try {
            $headers = \array_merge([":status" => $response->getStatus()], $response->getHeaders());

            // Remove headers that are obsolete in HTTP/2.
            unset($headers["connection"], $headers["keep-alive"], $headers["transfer-encoding"]);

            $headers["date"] = [$this->timeReference->getCurrentDate()];

            if (!empty($push = $response->getPush())) {
                foreach ($push as $url => $pushHeaders) {
                    if ($this->allowsPush) {
                        $this->dispatchInternalRequest($request, $id, $url, $pushHeaders);
                    } else {
                        $headers["link"][] = "<$url>; rel=preload";
                    }
                }
            }

            $headers = Internal\HPack::encode($headers);

            if (\strlen($headers) > $this->maxFrameSize) {
                $split = str_split($headers, $this->maxFrameSize);
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

            if ($request->getMethod() === "HEAD") {
                $this->writeData("", $id, true);
                return;
            }

            $buffer = "";
            $outputBufferSize = $this->options->getOutputBufferSize();

            while (null !== $part = yield) {
                // Stream may have been closed while waiting for body data.
                if (!isset($this->streams[$id])) {
                    return;
                }

                $buffer .= $part;

                if (\strlen($buffer) >= $outputBufferSize) {
                    $this->writeData($buffer, $id, false);
                    $buffer = "";
                }
            }

            // Stream may have been closed while waiting for body data.
            if (!isset($this->streams[$id])) {
                return;
            }

            $this->writeData($buffer, $id, true);
        } finally {
            if (isset($this->streams[$id]) && (!isset($headers) || isset($part))) {
                if (($buffer ?? "") !== "") {
                    $this->writeData($buffer, $id, false);
                }

                $this->writeFrame(pack("N", self::INTERNAL_ERROR), self::RST_STREAM, self::NOFLAG, $id);
                $this->releaseStream($id);

                if (isset($this->bodyEmitters[$id])) {
                    $this->bodyEmitters[$id]->fail(new ClientException("Stream error", Status::INTERNAL_SERVER_ERROR));
                    unset($this->bodyEmitters[$id]);
                }
            }
        }
    }

    private function writeData(string $data, int $stream, bool $last) {
        \assert(isset($this->streams[$stream]), "The stream was closed");

        $this->streams[$stream]->buffer .= $data;
        if ($last) {
            $this->streams[$stream]->state |= Http2Stream::LOCAL_CLOSED;
        }

        $this->writeBufferedData($stream);
    }

    private function writeBufferedData(int $id) {
        $delta = \min($this->window, $this->streams[$id]->window);
        $length = \strlen($this->streams[$id]->buffer);

        if ($delta >= $length) {
            $this->window -= $length;

            if ($length > $this->maxFrameSize) {
                $split = str_split($this->streams[$id]->buffer, $this->maxFrameSize);
                $this->streams[$id]->buffer = array_pop($split);
                foreach ($split as $part) {
                    $this->writeFrame($part, self::DATA, self::NOFLAG, $id);
                }
            }

            if ($this->streams[$id]->state & Http2Stream::LOCAL_CLOSED) {
                $this->writeFrame($this->streams[$id]->buffer, self::DATA, self::END_STREAM, $id);
                $this->releaseStream($id);
            } else {
                $this->writeFrame($this->streams[$id]->buffer, self::DATA, self::NOFLAG, $id);
                $this->streams[$id]->window -= $length;
                $this->streams[$id]->buffer = "";
            }
            return;
        }

        if ($delta > 0) {
            $data = $this->streams[$id]->buffer;
            $end = $delta - $this->maxFrameSize;

            for ($off = 0; $off < $end; $off += $this->maxFrameSize) {
                $this->writeFrame(substr($data, $off, $this->maxFrameSize), self::DATA, self::NOFLAG, $id);
            }

            $this->writeFrame(substr($data, $off, $delta - $off), self::DATA, self::NOFLAG, $id);

            $this->streams[$id]->buffer = substr($data, $delta);
            $this->streams[$id]->window -= $delta;
            $this->window -= $delta;
        }
    }

    protected function writePing(): Promise {
        // no need to receive the PONG frame, that's anyway registered by the keep-alive handler
        $data = $this->counter++;
        return $this->writeFrame($data, self::PING, self::NOFLAG);
    }

    protected function writeFrame(string $data, string $type, string $flags, int $stream = 0): Promise {
        \assert($stream === 0 || isset($this->streams[$stream]), "The stream was closed");

        $data = substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;

        return ($this->write)($data);
    }

    private function releaseStream(int $id) {
        unset($this->streams[$id], $this->trailerDeferreds[$id], $this->bodyEmitters[$id]);
        if ($id & 1) { // Client-initiated stream.
            $this->remainingStreams++;
        }
    }

    /**
     * @param string|null $settings HTTP2-Settings header content from upgrade request or null for direct HTTP/2.
     *
     * @return \Generator
     */
    private function parser(string $settings = null): \Generator {
        $maxHeaderSize = $this->options->getMaxHeaderSize();
        $maxBodySize = $this->options->getMaxBodySize();
        $maxFramesPerSecond = $this->options->getMaxFramesPerSecond();
        $lastReset = 0;
        $framesLastSecond = 0;

        $packedHeaders = [];
        $bodyLengths = [];

        if ($settings !== null) {
            if (\strlen($settings) % 6 !== 0) {
                $error = self::FRAME_SIZE_ERROR;
                goto connection_error;
            }

            while ($settings !== "") {
                if ($error = $this->updateSetting($settings)) {
                    goto connection_error;
                }
                $settings = substr($settings, 6);
            }

            // Upgraded connections automatically assume an initial stream with ID 1.
            $this->streams[1] = new Http2Stream(
                $this->initialWindowSize,
                Http2Stream::RESERVED | Http2Stream::REMOTE_CLOSED
            );
            $this->remainingStreams--;

            // Initial settings frame, sent immediately for upgraded connections.
            $this->writeFrame(
                pack(
                    "nNnNnN",
                    self::INITIAL_WINDOW_SIZE,
                    $maxBodySize + 256,
                    self::MAX_CONCURRENT_STREAMS,
                    $this->options->getMaxConcurrentStreams(),
                    self::MAX_HEADER_LIST_SIZE,
                    $maxHeaderSize
                ),
                self::SETTINGS,
                self::NOFLAG
            );
        }

        $buffer = yield;

        while (\strlen($buffer) < \strlen(self::PREFACE)) {
            $buffer .= yield;
        }

        if (\strncmp($buffer, self::PREFACE, \strlen(self::PREFACE)) !== 0) {
            $error = self::PROTOCOL_ERROR;
            goto connection_error;
        }

        $buffer = \substr($buffer, \strlen(self::PREFACE));

        if ($this->client->isEncrypted() && ($this->client->getCryptoContext()["alpn_protocol"] ?? null) !== "h2") {
            $error = self::CONNECT_ERROR;
            goto connection_error;
        }

        if ($settings === null) {
            // Initial settings frame, delayed until after the preface is read for non-upgraded connections.
            $this->writeFrame(
                pack(
                    "nNnNnN",
                    self::INITIAL_WINDOW_SIZE,
                    $maxBodySize + 256,
                    self::MAX_CONCURRENT_STREAMS,
                    $this->options->getMaxConcurrentStreams(),
                    self::MAX_HEADER_LIST_SIZE,
                    $maxHeaderSize
                ),
                self::SETTINGS,
                self::NOFLAG
            );
        }

        try {
            while (true) {
                if (++$framesLastSecond > $maxFramesPerSecond / 2) {
                    $time = $this->timeReference->getCurrentTime();
                    if ($lastReset === $time) {
                        if ($framesLastSecond > $maxFramesPerSecond) {
                            $buffer .= yield new Delayed(1000); // aka tiny frame DoS prevention
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

                if ($length > self::DEFAULT_MAX_FRAME_SIZE) { // Do we want to allow increasing max frame size?
                    $error = self::FRAME_SIZE_ERROR;
                    goto connection_error;
                }

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

                        if (isset($packedHeaders[$id])) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if (!isset($this->streams[$id], $this->bodyEmitters[$id], $this->trailerDeferreds[$id])) {
                            // Technically it is a protocol error to send data to a never opened stream
                            // but we do not want to store what streams WE have closed via RST_STREAM,
                            // thus we're just reporting them as closed
                            $error = self::STREAM_CLOSED;
                            goto stream_error;
                        }

                        if ($this->streams[$id]->state & Http2Stream::REMOTE_CLOSED) {
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

                        $remaining = $bodyLengths[$id] + $length - $this->streams[$id]->window;

                        if ($remaining > 0) {
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
                            $buffer .= yield $this->bodyEmitters[$id]->emit($body);
                        }

                        if (($flags & self::END_STREAM) !== "\0") {
                            $this->streams[$id]->state |= Http2Stream::REMOTE_CLOSED;

                            $deferred = $this->trailerDeferreds[$id];
                            $emitter = $this->bodyEmitters[$id];

                            unset($bodyLengths[$id]);
                            $this->releaseStream($id);

                            $deferred->resolve(new Trailers([]));
                            $emitter->complete();
                        } else {
                            $bodyLengths[$id] += $length;

                            if ($remaining === 0 && $length) {
                                $error = self::ENHANCE_YOUR_CALM;
                                goto connection_error;
                            }
                        }

                        continue 2;

                    case self::HEADERS:
                        if (isset($packedHeaders[$id])) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        if (isset($this->streams[$id])) {
                            if ($this->streams[$id]->state & Http2Stream::REMOTE_CLOSED) {
                                $error = self::STREAM_CLOSED;
                                goto stream_error;
                            }
                        } else {
                            if ($this->remainingStreams-- <= 0) {
                                $error = self::PROTOCOL_ERROR;
                                goto connection_error;
                            }

                            $this->streams[$id] = new Http2Stream($this->initialWindowSize);
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

                            /* Not needed until priority is handled.
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

                        if (($flags & self::END_STREAM) !== "\0") {
                            $this->streams[$id]->state |= Http2Stream::REMOTE_CLOSED;
                        }

                        if (($flags & self::END_HEADERS) !== "\0") {
                            goto parse_headers;
                        }

                        $packedHeaders[$id] = $packed;

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

                        /* @TODO PRIORITY frames values not used.
                        $dependency = unpack("N", $buffer);

                        if ($dependency < 0) {
                            $dependency = ~$dependency;
                            $exclusive = true;
                        } else {
                            $exclusive = false;
                        }

                        if ($dependency == 0) {
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        $weight = $buffer[4];
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
                            $this->bodyEmitters[$id]->fail(
                                new ClientException("Client ended stream", Status::BAD_REQUEST)
                            );
                            unset($this->bodyEmitters[$id]);
                        }

                        unset($packedHeaders[$id], $bodyLengths[$id]);
                        $this->releaseStream($id);

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
                            // Even with room for a few future options, sending that a big SETTINGS frame is just about
                            // wasting our processing time. I hereby declare this a protocol error.
                            $error = self::PROTOCOL_ERROR;
                            goto connection_error;
                        }

                        while ($length > 0) {
                            while (\strlen($buffer) < 6) {
                                $buffer .= yield;
                            }

                            if ($error = $this->updateSetting($buffer)) {
                                goto connection_error;
                            }

                            $buffer = \substr($buffer, 6);
                            $length -= 6;
                        }

                        $this->writeFrame("", self::SETTINGS, self::ACK);
                        continue 2;

                    // PUSH_PROMISE sent by client is a PROTOCOL_ERROR (just like undefined frame types)

                    case self::PING:
                        if ($length !== 8) {
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

                        if (($flags & self::ACK) === "\0") {
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
                        if ($length !== 4) {
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
                        $buffer = \substr($buffer, 4);

                        if ($id) {
                            // May receive a WINDOW_UPDATE frame for a closed stream.
                            if (isset($this->streams[$id])) {
                                $this->streams[$id]->window += $windowSize;

                                if ($this->streams[$id]->buffer !== "") {
                                    $this->writeBufferedData($id);
                                }
                            }

                            continue 2;
                        }

                        $this->window += $windowSize;

                        foreach ($this->streams as $id => $stream) {
                            if ($stream->buffer === "") {
                                continue;
                            }

                            $this->writeBufferedData($id);

                            if ($this->window === 0) {
                                break;
                            }
                        }

                        continue 2;

                    case self::CONTINUATION:
                        if (!isset($this->streams[$id], $packedHeaders[$id])) {
                            // technically it is a protocol error to send data to a never opened stream
                            // but we do not want to store what streams WE have closed via RST_STREAM,
                            // thus we're just reporting them as closed
                            $error = self::STREAM_CLOSED;
                            goto stream_error;
                        }

                        if ($this->streams[$id]->state & Http2Stream::REMOTE_CLOSED) {
                            $error = self::STREAM_CLOSED;
                            goto stream_error;
                        }

                        if ($length > $maxHeaderSize - \strlen($packedHeaders[$id])) {
                            $error = self::ENHANCE_YOUR_CALM;
                            goto stream_error;
                        }

                        while (\strlen($buffer) < $length) {
                            $buffer .= yield;
                        }

                        $packedHeaders[$id] .= \substr($buffer, 0, $length);
                        $buffer = \substr($buffer, $length);

                        if (($flags & self::END_HEADERS) !== "\0") {
                            $packed = $packedHeaders[$id];
                            unset($packedHeaders[$id]);
                            goto parse_headers;
                        }

                        continue 2;

                    default:
                        $error = self::PROTOCOL_ERROR;
                        goto connection_error;
                }

                parse_headers: {
                    $decoded = $this->table->decode($packed);
                    if ($decoded === null) {
                        $error = self::COMPRESSION_ERROR;
                        goto stream_error;
                    }

                    $headers = [];
                    foreach ($decoded as list($name, $value)) {
                        if (!\preg_match(self::HEADER_NAME_REGEX, $name)) {
                            $error = self::PROTOCOL_ERROR;
                            goto stream_error;
                        }

                        $headers[$name][] = $value;
                    }

                    if (isset($this->trailerDeferreds[$id]) && $this->streams[$id]->state & Http2Stream::RESERVED) {
                        $deferred = $this->trailerDeferreds[$id];
                        $emitter = $this->bodyEmitters[$id];
                        unset($bodyLengths[$id]);

                        // Trailers must not contain pseudo-headers.
                        foreach ($headers as $name => $value) {
                            if ($name[0] === ':') {
                                $error = self::PROTOCOL_ERROR;
                                goto stream_error;
                            }
                        }

                        $this->releaseStream($id);

                        $deferred->resolve(new Trailers($headers));
                        $emitter->complete();

                        continue;
                    }

                    if ($this->streams[$id]->state & Http2Stream::RESERVED) {
                        $error = self::PROTOCOL_ERROR;
                        goto stream_error;
                    }

                    $this->streams[$id]->state |= Http2Stream::RESERVED;

                    $target = $headers[":path"][0] ?? "";
                    $scheme = $headers[":scheme"][0] ?? ($this->client->isEncrypted() ? "https" : "http");
                    $host = $headers[":authority"][0] ?? "";

                    if (!\preg_match("#^([A-Z\d\.\-]+|\[[\d:]+\])(?::([1-9]\d*))?$#i", $host, $matches)) {
                        $error = self::PROTOCOL_ERROR;
                        goto stream_error;
                    }

                    $host = $matches[1];
                    $port = isset($matches[2]) ? (int) $matches[2] : $this->client->getLocalPort();
                    $host = \rawurldecode($host);
                    $authority = $port ? $host . ":" . $port : $host;

                    try {
                        $uri = new Uri($scheme . "://" . $authority . $target);
                    } catch (InvalidUriException $exception) {
                        $error = self::PROTOCOL_ERROR;
                        goto connection_error;
                    }

                    if ($this->streams[$id]->state & Http2Stream::REMOTE_CLOSED) {
                        $request = new Request(
                            $this->client,
                            $headers[":method"][0],
                            $uri,
                            $headers,
                            null,
                            "2.0"
                        );

                        $this->streamIdMap[\spl_object_hash($request)] = $id;
                        ($this->onMessage)($request);

                        continue;
                    }

                    $this->trailerDeferreds[$id] = $deferred = new Deferred;
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
                        },
                        $deferred->promise()
                    );

                    $request = new Request(
                        $this->client,
                        $headers[":method"][0],
                        $uri,
                        $headers,
                        $body,
                        "2.0"
                    );

                    $this->streamIdMap[\spl_object_hash($request)] = $id;
                    $bodyLengths[$id] = 0;
                    ($this->onMessage)($request);

                    continue;
                }

                stream_error: {
                    if (!isset($this->streams[$id])) {
                        $this->streams[$id] = new Http2Stream($this->initialWindowSize);
                    }

                    $this->writeFrame(pack("N", $error), self::RST_STREAM, self::NOFLAG, $id);
                    unset($packedHeaders[$id], $bodyLengths[$id]);

                    if (isset($this->trailerDeferreds[$id])) {
                        $deferred = $this->trailerDeferreds[$id];
                        $this->trailerDeferreds[$id] = null;
                        $deferred->fail(new ClientException("Stream error", Status::BAD_REQUEST));
                    }

                    if (isset($this->bodyEmitters[$id])) {
                        $emitter = $this->bodyEmitters[$id];
                        $this->bodyEmitters[$id] = null;
                        $emitter->fail(new ClientException("Stream error", Status::BAD_REQUEST));
                    }

                    $this->releaseStream($id);

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
            if (!empty($this->bodyEmitters) || !empty($this->trailerDeferreds)) {
                $exception = new ClientException("Client disconnected", Status::REQUEST_TIMEOUT);

                foreach ($this->trailerDeferreds as $id => $deferred) {
                    unset($this->trailerDeferreds[$id]);
                    $deferred->fail($exception);
                }

                foreach ($this->bodyEmitters as $id => $emitter) {
                    unset($this->bodyEmitters[$id]);
                    $emitter->fail($exception);
                }
            }
        }

        connection_error: {
            yield $this->writeFrame(pack("NN", 0, $error), self::GOAWAY, self::NOFLAG);
            $this->client->close();
        }
    }

    public function pendingRequestCount(): int {
        return \count($this->bodyEmitters);
    }

    /**
     * @param string $buffer Entire settings frame payload. Only the first 6 bytes are examined.
     *
     * @return int Error code, or 0 if the setting was valid or unknown.
     */
    private function updateSetting(string $buffer): int {
        $unpacked = \unpack("nsetting/Nvalue", $buffer);

        if ($unpacked["value"] < 0) {
            return self::PROTOCOL_ERROR;
        }

        switch ($unpacked["setting"]) {
            case self::HEADER_TABLE_SIZE:
                if ($unpacked["value"] > 4096) {
                    return self::PROTOCOL_ERROR;
                }

                $this->table->table_resize($unpacked["value"]);
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

            case self::MAX_FRAME_SIZE:
                if ($unpacked["value"] < 1 << 14 || $unpacked["value"] >= 1 << 24) {
                    return self::PROTOCOL_ERROR;
                }

                $this->maxFrameSize = $unpacked["value"];
                return 0;

            case self::MAX_HEADER_LIST_SIZE:
            case self::MAX_CONCURRENT_STREAMS:
                return 0; // @TODO Respect these settings from the client.

            default:
                return 0; // Unknown setting, ignore (6.5.2).
        }
    }
}
