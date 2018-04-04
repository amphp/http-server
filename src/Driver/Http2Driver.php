<?php

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\IteratorStream;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Http\HPack;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Internal\Http2Stream;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;
use League\Uri;
use Psr\Http\Message\UriInterface as PsrUri;
use function Amp\call;

final class Http2Driver implements HttpDriver {
    const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    const DEFAULT_MAX_FRAME_SIZE = 1 << 14;
    const DEFAULT_WINDOW_SIZE = (1 << 16) - 1;

    const MAX_INCREMENT = (1 << 31) - 1;

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
    const INITIAL_WINDOW_SIZE = 0x4; // 1 << 16 - 1
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

    /** @var string 64-bit for ping. */
    private $counter = "aaaaaaaa";

    /** @var \Amp\Http\Server\Driver\Client */
    private $client;

    /** @var \Amp\Http\Server\Options */
    private $options;

    /** @var \Amp\Http\Server\Driver\TimeReference */
    private $timeReference;

    /** @var int */
    private $serverWindow = self::DEFAULT_WINDOW_SIZE;

    /** @var int */
    private $clientWindow = self::DEFAULT_WINDOW_SIZE;

    /** @var int */
    private $initialWindowSize = self::DEFAULT_WINDOW_SIZE;

    /** @var int */
    private $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE;

    /** @var bool */
    private $allowsPush = true;

    /** @var int Last used stream ID. */
    private $streamId = 0;

    /** @var \Amp\Http\Server\Driver\Internal\Http2Stream[] */
    private $streams = [];

    /** @var int[] Map of request hashes to stream IDs. */
    private $streamIdMap = [];

    /** @var int[] Map of URLs pushed on this connection. */
    private $pushCache = [];

    /** @var \Amp\Deferred[] */
    private $trailerDeferreds = [];

    /** @var \Amp\Emitter[] */
    private $bodyEmitters = [];

    /** @var int Number of streams that may be opened. */
    private $remainingStreams;

    /** @var bool */
    private $stopping = false;

    /** @var callable */
    private $onMessage;

    /** @var callable */
    private $write;

    /** @var \Amp\Http\HPack */
    private $table;

    public function __construct(Options $options, TimeReference $timeReference) {
        $this->options = $options;
        $this->timeReference = $timeReference;

        $this->remainingStreams = $this->options->getConcurrentStreamLimit();

        $this->table = new HPack;
    }

    /**
     * @param Client      $client
     * @param callable    $onMessage
     * @param callable    $write
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

    protected function dispatchInternalRequest(Request $request, int $streamId, PsrUri $url, array $headers = []) {
        $uri = $request->getUri();

        if ($url[0] ?? "" === "/") {
            $uri = $uri->withPath($url->getPath())->withQuery($url->getQuery());
        } elseif ($url->getScheme() !== "") {
            $uri = $uri->withPath($uri->getPath() . "/" . $url->getPath())->withQuery($url->getQuery());
        } else {
            $uri = $url;
        }

        $url = (string) $uri;

        if (isset($this->pushCache[$url])) {
            return; // Resource already pushed to this client.
        }

        $this->pushCache[$url] = $streamId;

        $path = $uri->getPath();
        if ($query = $uri->getQuery()) {
            $path .= "?" . $query;
        }

        $headers = \array_merge([
            ":authority" => [$uri->getAuthority()],
            ":scheme" => [$uri->getScheme()],
            ":path" => [$path],
            ":method" => ["GET"],
        ], \array_intersect_key($request->getHeaders(), self::PUSH_PROMISE_INTERSECT), $headers);

        $id = $this->streamId += 2; // Server initiated stream IDs must be even.
        $request = new Request($this->client, "GET", $uri, $headers, null, "2.0");
        $this->streamIdMap[\spl_object_hash($request)] = $id;

        $this->streams[$id] = $stream = new Http2Stream(
            0, // No data will be incoming on this stream.
            $this->initialWindowSize,
            Http2Stream::RESERVED | Http2Stream::REMOTE_CLOSED
        );

        $headers = pack("N", $id) . $this->table->encode($headers);
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

        $stream->pendingResponse = ($this->onMessage)($request);
    }

    public function write(Request $request, Response $response): Promise {
        \assert($this->client, "The driver has not been setup");

        $hash = \spl_object_hash($request);
        $id = $this->streamIdMap[$hash] ?? 1; // Default ID of 1 for upgrade requests.
        unset($this->streamIdMap[$hash]);

        if (!isset($this->streams[$id])) {
            return new Success; // Client closed the stream or connection.
        }

        $stream = $this->streams[$id]; // $this->streams[$id] may be unset in send().
        return $stream->pendingWrite = new Coroutine($this->send($id, $response, $request));
    }

    public function send(int $id, Response $response, Request $request): \Generator {
        $part = ""; // Required for the finally, not directly overwritten, even if your IDE says otherwise.

        try {
            $status = $response->getStatus();

            if ($status < Status::OK) {
                $response->setStatus(Status::HTTP_VERSION_NOT_SUPPORTED);
                throw new ClientException("1xx response codes are not supported in HTTP/2", self::HTTP_1_1_REQUIRED);
            }

            if ($status === Status::HTTP_VERSION_NOT_SUPPORTED && $response->getHeader("upgrade")) {
                throw new ClientException("Upgrade requests require HTTP/1.1", self::HTTP_1_1_REQUIRED);
            }

            $headers = \array_merge([":status" => $status], $response->getHeaders());

            // Remove headers that are obsolete in HTTP/2.
            unset($headers["connection"], $headers["keep-alive"], $headers["transfer-encoding"]);

            $headers["date"] = [$this->timeReference->getCurrentDate()];

            if (!empty($push = $response->getPush())) {
                foreach ($push as list($pushUri, $pushHeaders)) {
                    if ($this->allowsPush) {
                        $this->dispatchInternalRequest($request, $id, $pushUri, $pushHeaders);
                    } else {
                        $headers["link"][] = "<$pushUri>; rel=preload";
                    }
                }
            }

            $headers = $this->table->encode($headers);

            if (\strlen($headers) > $this->maxFrameSize) {
                $split = str_split($headers, $this->maxFrameSize);
                $headers = array_shift($split);
                yield $this->writeFrame($headers, self::HEADERS, self::NOFLAG, $id);

                $headers = array_pop($split);
                foreach ($split as $msgPart) {
                    yield $this->writeFrame($msgPart, self::CONTINUATION, self::NOFLAG, $id);
                }
                yield $this->writeFrame($headers, self::CONTINUATION, self::END_HEADERS, $id);
            } else {
                yield $this->writeFrame($headers, self::HEADERS, self::END_HEADERS, $id);
            }

            if ($request->getMethod() === "HEAD") {
                $this->writeData("", $id, true);
                $part = null;
                return;
            }

            $buffer = "";
            $body = $response->getBody();

            while (null !== $buffer = yield $body->read()) {
                // Stream may have been closed while waiting for body data.
                if (!isset($this->streams[$id])) {
                    return;
                }

                yield $this->writeData($buffer, $id, false);
                $buffer = "";
            }

            // Stream may have been closed while waiting for body data.
            if (!isset($this->streams[$id])) {
                return;
            }

            yield $this->writeData("", $id, true);
        } catch (ClientException $exception) {
            $error = $exception->getCode() ?? self::CANCEL; // Set error code to be used in finally below.
        } finally {
            if (isset($this->streams[$id]) && $part !== null) {
                if (($buffer ?? "") !== "") {
                    $this->writeData($buffer, $id, false);
                }

                $error = $error ?? self::INTERNAL_ERROR;

                $this->writeFrame(pack("N", $error), self::RST_STREAM, self::NOFLAG, $id);
                $this->releaseStream($id);

                if (isset($this->bodyEmitters[$id], $this->trailerDeferreds)) {
                    $exception = $exception ?? new ClientException("Stream error", $error);

                    $emitter = $this->bodyEmitters[$id];
                    $deferred = $this->trailerDeferreds[$id];

                    unset($this->bodyEmitters[$id], $this->trailerDeferreds[$id]);

                    $emitter->fail($exception);
                    $deferred->fail($exception);
                }
            }
        }
    }

    private function writeData(string $data, int $stream, bool $last): Promise {
        \assert(isset($this->streams[$stream]), "The stream was closed");

        $this->streams[$stream]->buffer .= $data;
        if ($last) {
            $this->streams[$stream]->state |= Http2Stream::LOCAL_CLOSED;
        }

        return $this->writeBufferedData($stream);
    }

    private function writeBufferedData(int $id): Promise {
        $stream = $this->streams[$id];
        $delta = \min($this->clientWindow, $stream->clientWindow);
        $length = \strlen($stream->buffer);

        if ($delta >= $length) {
            $this->clientWindow -= $length;

            if ($length > $this->maxFrameSize) {
                $split = str_split($stream->buffer, $this->maxFrameSize);
                $stream->buffer = array_pop($split);
                foreach ($split as $part) {
                    $this->writeFrame($part, self::DATA, self::NOFLAG, $id);
                }
            }

            if ($stream->state & Http2Stream::LOCAL_CLOSED) {
                $promise = $this->writeFrame($stream->buffer, self::DATA, self::END_STREAM, $id);
                $this->releaseStream($id);
            } else {
                $promise = $this->writeFrame($stream->buffer, self::DATA, self::NOFLAG, $id);
                $stream->clientWindow -= $length;
                $stream->buffer = "";
            }

            if ($stream->deferred) {
                $deferred = $stream->deferred;
                $stream->deferred = null;
                $deferred->resolve();
            }

            return $promise;
        }

        if ($delta > 0) {
            $data = $stream->buffer;
            $end = $delta - $this->maxFrameSize;

            for ($off = 0; $off < $end; $off += $this->maxFrameSize) {
                $this->writeFrame(substr($data, $off, $this->maxFrameSize), self::DATA, self::NOFLAG, $id);
            }

            $promise = $this->writeFrame(substr($data, $off, $delta - $off), self::DATA, self::NOFLAG, $id);

            $stream->buffer = substr($data, $delta);
            $stream->clientWindow -= $delta;
            $this->clientWindow -= $delta;

            return $promise;
        }

        if ($stream->deferred === null) {
            $stream->deferred = new Deferred;
        }

        return $stream->deferred->promise();
    }

    protected function writePing(): Promise {
        // no need to receive the PONG frame, that's anyway registered by the keep-alive handler
        $data = $this->counter++;
        return $this->writeFrame($data, self::PING, self::NOFLAG);
    }

    protected function writeFrame(string $data, string $type, string $flags, int $stream = 0): Promise {
        $data = substr(pack("N", \strlen($data)), 1, 3) . $type . $flags . pack("N", $stream) . $data;
        return ($this->write)($data);
    }

    private function releaseStream(int $id) {
        \assert(isset($this->streams[$id]), "Tried to release a non-existent stream");

        if (isset($this->bodyEmitters[$id])) {
            $this->bodyEmitters[$id]->complete();
        }

        if (isset($this->trailerDeferreds[$id])) {
            $this->trailerDeferreds[$id]->resolve(new Trailers([]));
        }

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
        $maxHeaderSize = $this->options->getHeaderSizeLimit();
        $maxBodySize = $this->options->getBodySizeLimit();
        $maxFramesPerSecond = $this->options->getFramesPerSecondLimit();
        $minAverageFrameSize = $this->options->getMinimumAverageFrameSize();

        $frameCount = 0;
        $bytesReceived = 0;
        $lastReset = $this->timeReference->getCurrentTime();

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

            $this->clientWindow = $this->initialWindowSize;

            // Upgraded connections automatically assume an initial stream with ID 1.
            $this->streams[1] = new Http2Stream(
                0, // No data will be incoming on this stream.
                $this->initialWindowSize,
                Http2Stream::RESERVED | Http2Stream::REMOTE_CLOSED
            );
            $this->remainingStreams--;

            // Initial settings frame, sent immediately for upgraded connections.
            $this->writeFrame(
                pack(
                    "nNnNnNnN",
                    self::INITIAL_WINDOW_SIZE,
                    $maxBodySize,
                    self::MAX_CONCURRENT_STREAMS,
                    $this->options->getConcurrentStreamLimit(),
                    self::MAX_HEADER_LIST_SIZE,
                    $maxHeaderSize,
                    self::MAX_FRAME_SIZE,
                    self::DEFAULT_MAX_FRAME_SIZE
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
                    "nNnNnNnN",
                    self::INITIAL_WINDOW_SIZE,
                    $maxBodySize,
                    self::MAX_CONCURRENT_STREAMS,
                    $this->options->getConcurrentStreamLimit(),
                    self::MAX_HEADER_LIST_SIZE,
                    $maxHeaderSize,
                    self::MAX_FRAME_SIZE,
                    self::DEFAULT_MAX_FRAME_SIZE
                ),
                self::SETTINGS,
                self::NOFLAG
            );
        }

        try {
            while (true) {
                if (++$frameCount === $maxFramesPerSecond) {
                    $now = $this->timeReference->getCurrentTime();
                    if ($lastReset === $now && $bytesReceived / $maxFramesPerSecond < $minAverageFrameSize) {
                        $error = self::ENHANCE_YOUR_CALM;
                        goto connection_error;
                    }

                    $lastReset = $now;
                    $frameCount = 0;
                    $bytesReceived = 0;
                }

                while (\strlen($buffer) < 9) {
                    $buffer .= yield;
                }

                $length = \unpack("N", "\0$buffer")[1];
                $bytesReceived += $length;

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

                        if (!isset($this->streams[$id], $this->bodyEmitters[$id], $this->trailerDeferreds[$id])) {
                            // Technically it is a protocol error to send data to a never opened stream
                            // but we do not want to store what streams WE have closed via RST_STREAM,
                            // thus we're just reporting them as closed
                            $error = self::STREAM_CLOSED;
                            goto stream_error;
                        }

                        $stream = $this->streams[$id];

                        if ($stream->headers !== null) {
                            $error = self::PROTOCOL_ERROR;
                            goto stream_error;
                        }

                        if ($stream->state & Http2Stream::REMOTE_CLOSED) {
                            $error = self::PROTOCOL_ERROR;
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

                        $this->serverWindow -= $length;
                        $stream->serverWindow -= $length;
                        $stream->received += $length;

                        if ($stream->received >= $stream->maxBodySize && ($flags & self::END_STREAM) === "\0") {
                            $error = self::CANCEL;
                            goto stream_error;
                        }

                        if ($stream->serverWindow <= 0 && ($increment = $stream->maxBodySize - $stream->received)) {
                            if ($increment > self::MAX_INCREMENT) {
                                $increment = self::MAX_INCREMENT;
                            }

                            $stream->serverWindow += $increment;

                            $this->writeFrame(pack("N", $increment), self::WINDOW_UPDATE, self::NOFLAG, $id);
                        }

                        if ($this->serverWindow <= 0) {
                            $increment = \max($stream->serverWindow, $maxBodySize);
                            $this->serverWindow += $increment;

                            $this->writeFrame(pack("N", $increment), self::WINDOW_UPDATE, self::NOFLAG);
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
                            $stream->state |= Http2Stream::REMOTE_CLOSED;

                            if (!isset($this->bodyEmitters[$id], $this->trailerDeferreds[$id])) {
                                continue 2; // Stream closed after emitting body fragment.
                            }

                            $deferred = $this->trailerDeferreds[$id];
                            $emitter = $this->bodyEmitters[$id];

                            unset($this->bodyEmitters[$id], $this->trailerDeferreds[$id]);

                            $deferred->resolve(new Trailers([]));
                            $emitter->complete();
                        }

                        continue 2;

                    case self::HEADERS:
                        if (isset($this->streams[$id])) {
                            $stream = $this->streams[$id];

                            if ($stream->headers !== null) {
                                $error = self::PROTOCOL_ERROR;
                                goto connection_error;
                            }

                            if ($stream->state & Http2Stream::REMOTE_CLOSED) {
                                $error = self::STREAM_CLOSED;
                                goto stream_error;
                            }
                        } else {
                            if ($this->remainingStreams-- <= 0) {
                                $error = self::PROTOCOL_ERROR;
                                goto connection_error;
                            }

                            $stream = $this->streams[$id] = new Http2Stream($maxBodySize, $this->initialWindowSize);
                        }

                        if (($flags & self::PADDED) !== "\0") {
                            if ($buffer === "") {
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

                        $stream->headers = \substr($buffer, 0, $length - $padding);
                        $buffer = \substr($buffer, $length);

                        if (($flags & self::END_STREAM) !== "\0") {
                            $stream->state |= Http2Stream::REMOTE_CLOSED;
                        }

                        if (($flags & self::END_HEADERS) !== "\0") {
                            goto parse_headers;
                        }

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

                        if (isset($this->bodyEmitters[$id], $this->trailerDeferreds[$id])) {
                            $exception = new ClientException("Client ended stream", self::STREAM_CLOSED);

                            $emitter = $this->bodyEmitters[$id];
                            $deferred = $this->trailerDeferreds[$id];

                            unset($this->bodyEmitters[$id], $this->trailerDeferreds[$id]);

                            $emitter->fail($exception);
                            $deferred->fail($exception);
                        }

                        if (isset($this->streams[$id])) {
                            $this->releaseStream($id);
                        }

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

                        if ($length % 6 !== 0) {
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

                        yield $this->shutdown($lastId);
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
                                $this->streams[$id]->clientWindow += $windowSize;

                                if ($this->streams[$id]->buffer !== "") {
                                    $this->writeBufferedData($id);
                                }
                            }

                            continue 2;
                        }

                        $this->clientWindow += $windowSize;

                        foreach ($this->streams as $id => $stream) {
                            if ($stream->buffer === "") {
                                continue;
                            }

                            $this->writeBufferedData($id);

                            if ($this->clientWindow === 0) {
                                break;
                            }
                        }

                        continue 2;

                    case self::CONTINUATION:
                        if (!isset($this->streams[$id])) {
                            // technically it is a protocol error to send data to a never opened stream
                            // but we do not want to store what streams WE have closed via RST_STREAM,
                            // thus we're just reporting them as closed
                            $error = self::STREAM_CLOSED;
                            goto stream_error;
                        }

                        $stream = $this->streams[$id];

                        if ($stream->headers === null) {
                            $error = self::PROTOCOL_ERROR;
                            goto stream_error;
                        }

                        if ($stream->state & Http2Stream::REMOTE_CLOSED) {
                            $error = self::STREAM_CLOSED;
                            goto stream_error;
                        }

                        if ($length > $maxHeaderSize - \strlen($stream->headers)) {
                            $error = self::ENHANCE_YOUR_CALM;
                            goto stream_error;
                        }

                        while (\strlen($buffer) < $length) {
                            $buffer .= yield;
                        }

                        $stream->headers .= \substr($buffer, 0, $length);
                        $buffer = \substr($buffer, $length);

                        if (($flags & self::END_STREAM) !== "\0") {
                            $stream->state |= Http2Stream::REMOTE_CLOSED;
                        }

                        if (($flags & self::END_HEADERS) !== "\0") {
                            goto parse_headers;
                        }

                        continue 2;

                    default:
                        $error = self::PROTOCOL_ERROR;
                        goto connection_error;
                }

                parse_headers: {
                    $decoded = $this->table->decode($stream->headers, $maxHeaderSize);
                    $stream->headers = null;

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

                    if (isset($this->trailerDeferreds[$id]) && $stream->state & Http2Stream::RESERVED) {
                        $deferred = $this->trailerDeferreds[$id];
                        $emitter = $this->bodyEmitters[$id];

                        unset($this->bodyEmitters[$id], $this->trailerDeferreds[$id]);

                        // Trailers must not contain pseudo-headers.
                        foreach ($headers as $name => $value) {
                            if ($name[0] === ':') {
                                $error = self::PROTOCOL_ERROR;
                                goto stream_error;
                            }
                        }

                        $deferred->resolve(new Trailers($headers));
                        $emitter->complete();

                        continue;
                    }

                    if ($stream->state & Http2Stream::RESERVED) {
                        $error = self::PROTOCOL_ERROR;
                        goto stream_error;
                    }

                    $stream->state |= Http2Stream::RESERVED;

                    if ($this->stopping) {
                        continue; // Do not dispatch more requests if stopping.
                    }

                    if (!isset($headers[":method"][0])) {
                        $error = self::PROTOCOL_ERROR;
                        goto stream_error;
                    }

                    $target = $headers[":path"][0] ?? "";
                    $scheme = $headers[":scheme"][0] ?? ($this->client->isEncrypted() ? "https" : "http");
                    $host = $headers[":authority"][0] ?? "";
                    $query = null;

                    if (!\preg_match("#^([A-Z\d\.\-]+|\[[\d:]+\])(?::([1-9]\d*))?$#i", $host, $matches)) {
                        $error = self::PROTOCOL_ERROR;
                        goto stream_error;
                    }

                    $host = $matches[1];
                    $port = isset($matches[2]) ? (int) $matches[2] : $this->client->getLocalPort();

                    if ($position = \strpos($target, "#")) {
                        $target = \substr($target, 0, $position);
                    }

                    if ($position = \strpos($target, "?")) {
                        $query = \substr($target, $position + 1);
                        $target = \substr($target, 0, $position);
                    }

                    try {
                        $uri = Uri\Http::createFromComponents([
                            "scheme" => $scheme,
                            "host"   => $host,
                            "port"   => $port,
                            "path"   => $target,
                            "query"  => $query,
                        ]);
                    } catch (Uri\UriException $exception) {
                        $error = self::PROTOCOL_ERROR;
                        goto stream_error;
                    }

                    if ($stream->state & Http2Stream::REMOTE_CLOSED) {
                        $request = new Request(
                            $this->client,
                            $headers[":method"][0],
                            $uri,
                            $headers,
                            null,
                            "2.0"
                        );

                        $this->streamIdMap[\spl_object_hash($request)] = $id;
                        $stream->pendingResponse = ($this->onMessage)($request);

                        // Must null reference to Request object so it is destroyed when request handler completes.
                        $request = null;

                        continue;
                    }

                    $this->trailerDeferreds[$id] = $deferred = new Deferred;
                    $this->bodyEmitters[$id] = new Emitter;

                    $body = new RequestBody(
                        new IteratorStream($this->bodyEmitters[$id]->iterate()),
                        function (int $bodySize) use ($id) {
                            if (!isset($this->streams[$id], $this->bodyEmitters[$id])) {
                                return;
                            }

                            if ($this->streams[$id]->maxBodySize >= $bodySize) {
                                return;
                            }

                            $this->streams[$id]->maxBodySize = $bodySize;
                        },
                        $deferred->promise()
                    );

                    if ($this->serverWindow <= $maxBodySize >> 1) {
                        $increment = $maxBodySize - $this->serverWindow;
                        $this->serverWindow = $maxBodySize;
                        $this->writeFrame(pack("N", $increment), self::WINDOW_UPDATE, self::NOFLAG);
                    }

                    $request = new Request(
                        $this->client,
                        $headers[":method"][0],
                        $uri,
                        $headers,
                        $body,
                        "2.0"
                    );

                    $this->streamIdMap[\spl_object_hash($request)] = $id;
                    $stream->pendingResponse = ($this->onMessage)($request);

                    // Must null reference to Request and Body objects
                    // so they are destroyed when request handler completes.
                    $request = $body = null;

                    continue;
                }

                stream_error: {
                    $this->writeFrame(pack("N", $error), self::RST_STREAM, self::NOFLAG, $id);

                    if (isset($this->bodyEmitters[$id], $this->trailerDeferreds[$id])) {
                        $exception = new ClientException("Stream error", self::CANCEL);

                        $this->trailerDeferreds[$id]->fail($exception);
                        $this->bodyEmitters[$id]->fail($exception);
                    }

                    if (isset($this->streams[$id])) {
                        $this->releaseStream($id);
                    }

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
                $exception = new ClientException("Client disconnected", self::CANCEL);

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
            yield $this->shutdown(null, $error ?? self::PROTOCOL_ERROR);
            $this->client->close();
        }
    }

    /** @inheritdoc */
    public function stop(): Promise {
        return $this->shutdown();
    }

    /**
     * @param int|null $lastId ID of last processed frame. Null to use the last opened frame ID or 0 if no frames have
     *                         been opened.
     *
     * @return Promise
     */
    public function shutdown(int $lastId = null, int $reason = self::GRACEFUL_SHUTDOWN): Promise {
        $this->stopping = true;

        return call(function () use ($lastId, $reason) {
            $promises = [];
            foreach ($this->streams as $id => $stream) {
                if ($lastId && $id > $lastId) {
                    break;
                }

                if ($stream->pendingResponse) {
                    $promises[] = $stream->pendingResponse;
                }
            }

            $lastId = $lastId ?? ($id ?? 0);

            yield $this->writeFrame(pack("NN", $lastId, $reason), self::GOAWAY, self::NOFLAG);

            yield $promises;

            $promises = [];
            foreach ($this->streams as $id => $stream) {
                if ($lastId && $id > $lastId) {
                    break;
                }

                if ($stream->pendingWrite) {
                    $promises[] = $stream->pendingWrite;
                }
            }

            yield $promises;
        });
    }

    public function getPendingRequestCount(): int {
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

            case self::HEADER_TABLE_SIZE:
            case self::MAX_HEADER_LIST_SIZE:
            case self::MAX_CONCURRENT_STREAMS:
                return 0; // @TODO Respect these settings from the client.

            default:
                return 0; // Unknown setting, ignore (6.5.2).
        }
    }
}
