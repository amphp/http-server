<?php

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\HPack;
use Amp\Http\Http2\Http2ConnectionException;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\Http2\Http2Processor;
use Amp\Http\Http2\Http2StreamException;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Internal\AbstractHttpDriver;
use Amp\Http\Server\Driver\Internal\Http2Stream;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Push;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Http\Status;
use Amp\Pipeline\Queue;
use League\Uri;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Http\formatDateHeader;

final class Http2Driver extends AbstractHttpDriver implements Http2Processor
{
    public const DEFAULT_MAX_FRAME_SIZE = 1 << 14;
    public const DEFAULT_WINDOW_SIZE = (1 << 16) - 1;

    private const MINIMUM_WINDOW = (1 << 15) - 1;
    private const MAX_INCREMENT = (1 << 16) - 1;

    // Headers to take over from original request if present
    private const PUSH_PROMISE_INTERSECT = [
        "accept" => true,
        "accept-charset" => true,
        "accept-encoding" => true,
        "accept-language" => true,
        "authorization" => true,
        "cache-control" => true,
        "cookie" => true,
        "date" => true,
        "host" => true,
        "user-agent" => true,
        "via" => true,
    ];

    /** @var string 64-bit for ping. */
    private string $counter = "aaaaaaaa";

    private Client $client;
    private ReadableStream $readableStream;
    private WritableStream $writableStream;

    private Options $options;

    private PsrLogger $logger;

    private ?string $settings;

    private int $serverWindow = self::DEFAULT_WINDOW_SIZE;

    private int $clientWindow = self::DEFAULT_WINDOW_SIZE;

    private int $initialWindowSize = self::DEFAULT_WINDOW_SIZE;

    private int $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE;

    private bool $allowsPush;

    /** @var int Last used local stream ID. */
    private int $localStreamId = 0;

    /** @var int Last used remote stream ID. */
    private int $remoteStreamId = 0;

    /** @var Http2Stream[] */
    private array $streams = [];

    /** @var int[] Map of request hashes to stream IDs. */
    private array $streamIdMap = [];

    /** @var int[] Map of URLs pushed on this connection. */
    private array $pushCache = [];

    /** @var DeferredFuture[] */
    private array $trailerDeferreds = [];

    /** @var Queue[] */
    private array $bodyQueues = [];

    /** @var int Number of streams that may be opened. */
    private int $remainingStreams;

    private bool $stopping = false;

    private int $pinged = 0;

    private HPack $hpack;

    public function __construct(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        Options $options,
        ?string $settings = null
    ) {
        parent::__construct($requestHandler, $errorHandler, $logger, $options);

        $this->options = $options;
        $this->logger = $logger;
        $this->settings = $settings;

        $this->remainingStreams = $this->options->getConcurrentStreamLimit();
        $this->allowsPush = $this->options->isPushEnabled();

        $this->hpack = new HPack;
    }

    public function handleClient(Client $client, ReadableStream $readableStream, WritableStream $writableStream): void
    {
        \assert(!isset($this->client), "The driver has already been setup");

        $this->client = $client;
        $this->readableStream = $readableStream;
        $this->writableStream = $writableStream;

        $this->processClientInput(fn() => $this->readPreface());
    }

    // Provide separate functions for Http2Driver initialization:
    // The Http1Driver may still be in process of reading a possible request body.
    // As we want to be able to already start sending HTTP/2 frames before the whole request body has been read, we need to initialize writing early.
    // Hence we need a separate function for starting reading on the stream.
    public function initializeWriting(Client $client, WritableStream $writableStream)
    {
        \assert(!isset($this->client), "The driver has already been setup");

        $this->client = $client;
        $this->writableStream = $writableStream;
    }

    public function handleClientWithBuffer(string $buffer, ReadableStream $readableStream) {
        $this->readableStream = $readableStream;
        if ($this->settings !== null) {
            // Upgraded connections automatically assume an initial stream with ID 1.
            $this->streams[1] = new Http2Stream(
                0, // No data will be incoming on this stream.
                $this->initialWindowSize,
                $this->initialWindowSize,
                Http2Stream::RESERVED | Http2Stream::REMOTE_CLOSED
            );
            $this->remainingStreams--;

            // Initial settings frame, sent immediately for upgraded connections.
            $this->writeFrame(
                \pack(
                    "nNnNnNnN",
                    Http2Parser::INITIAL_WINDOW_SIZE,
                    $this->options->getBodySizeLimit(),
                    Http2Parser::MAX_CONCURRENT_STREAMS,
                    $this->options->getConcurrentStreamLimit(),
                    Http2Parser::MAX_HEADER_LIST_SIZE,
                    $this->options->getHeaderSizeLimit(),
                    Http2Parser::MAX_FRAME_SIZE,
                    self::DEFAULT_MAX_FRAME_SIZE
                ),
                Http2Parser::SETTINGS,
                Http2Parser::NO_FLAG
            );
        }

        $this->readableStream = $readableStream;

        $this->processClientInput(fn() => $buffer);
    }

    private function processClientInput($parserInput)
    {
        // TODO: $this->client->updateExpirationTime(\time() + $this->options->getHttp2Timeout());
        $parser = (new Http2Parser($this))->parse($this->settings);

        try {
            $parser->send($parserInput());

            while (null !== $chunk = $this->readableStream->read()) {
                $parser->send($chunk);

                if (!$parser->valid()) {
                    return;
                }
            }

            $this->shutdown();
        } catch (Http2ConnectionException $exception) {
            $this->shutdown(null, $exception);
        } finally {
            $this->client->close();
            $this->readableStream->close();
            $this->writableStream->close();
        }
    }

    protected function write(Request $request, Response $response): void
    {
        \assert(isset($this->client), "The driver has not been setup");

        $hash = \spl_object_hash($request);
        $id = $this->streamIdMap[$hash] ?? 1; // Default ID of 1 for upgrade requests.
        unset($this->streamIdMap[$hash]);

        if (!isset($this->streams[$id])) {
            return; // Client closed the stream or connection.
        }

        // TODO: $this->client->updateExpirationTime(\time() + $this->options->getHttp2Timeout());

        $stream = $this->streams[$id]; // $this->streams[$id] may be unset in send().
        $deferred = new DeferredFuture;
        $stream->pendingWrite = $deferred->getFuture();

        try {
            $this->send($id, $response, $request);
        } finally {
            $deferred->complete();
        }
    }

    public function stop(): void
    {
        $this->shutdown();
    }

    public function getPendingRequestCount(): int
    {
        return \count($this->bodyQueues);
    }

    private function send(int $id, Response $response, Request $request): void
    {
        $chunk = ""; // Required for the finally, not directly overwritten, even if your IDE says otherwise.

        try {
            $status = $response->getStatus();

            if ($status < Status::OK) {
                $response->setStatus(Status::HTTP_VERSION_NOT_SUPPORTED);
                throw new ClientException(
                    $this->client,
                    "1xx response codes are not supported in HTTP/2",
                    Http2Parser::HTTP_1_1_REQUIRED
                );
            }

            if ($status === Status::HTTP_VERSION_NOT_SUPPORTED && $response->getHeader("upgrade")) {
                throw new ClientException(
                    $this->client,
                    "Upgrade requests require HTTP/1.1",
                    Http2Parser::HTTP_1_1_REQUIRED
                );
            }

            $headers = \array_merge([":status" => $status], $response->getHeaders());

            // Remove headers that are obsolete in HTTP/2.
            unset($headers["connection"], $headers["keep-alive"], $headers["transfer-encoding"]);

            $trailers = $response->getTrailers();

            if ($trailers !== null && !isset($headers["trailer"]) && ($fields = $trailers->getFields())) {
                $headers["trailer"] = [\implode(", ", $fields)];
            }

            $headers["date"] = [formatDateHeader()];

            foreach ($response->getPushes() as $push) {
                $headers["link"][] = "<{$push->getUri()}>; rel=preload";
                if ($this->allowsPush) {
                    $this->sendPushPromise($request, $id, $push);
                }
            }

            $rawHeaders = $this->encodeHeaders($headers);

            if (\strlen($rawHeaders) > $this->maxFrameSize) {
                // Header frames must be sent as one contiguous block without frames from any other stream being
                // interleaved between due to HPack. See https://datatracker.ietf.org/doc/html/rfc7540#section-4.3
                $split = \str_split($rawHeaders, $this->maxFrameSize);
                $rawHeaders = \array_shift($split);
                async(fn () => $this->writeFrame($rawHeaders, Http2Parser::HEADERS, Http2Parser::NO_FLAG,
                    $id))->ignore();

                $rawHeaders = \array_pop($split);
                foreach ($split as $msgPart) {
                    async(fn () => $this->writeFrame($msgPart, Http2Parser::CONTINUATION, Http2Parser::NO_FLAG,
                        $id))->ignore();
                }
                async(fn () => $this->writeFrame($rawHeaders, Http2Parser::CONTINUATION, Http2Parser::END_HEADERS,
                    $id))->await();
            } else {
                $this->writeFrame($rawHeaders, Http2Parser::HEADERS, Http2Parser::END_HEADERS, $id);
            }

            if ($request->getMethod() === "HEAD") {
                $this->streams[$id]->state |= Http2Stream::LOCAL_CLOSED;
                $this->writeData("", $id);
                $chunk = null;
                return;
            }

            $buffer = "";
            $body = $response->getBody();
            $streamThreshold = $this->options->getStreamThreshold();

            while (null !== $chunk = $body->read()) {
                $buffer .= $chunk;

                if (\strlen($buffer) < $streamThreshold) {
                    continue;
                }

                $this->writeData($buffer, $id);

                $buffer = $chunk = ""; // Don't use null here because of finally.
            }

            // Stream may have been closed while waiting for body data.
            if (!isset($this->streams[$id])) {
                return;
            }

            if ($trailers === null) {
                $this->streams[$id]->state |= Http2Stream::LOCAL_CLOSED;
            }

            $this->writeData($buffer, $id);

            // Stream may have been closed while writing final body chunk.
            if (!isset($this->streams[$id])) {
                return;
            }

            if ($trailers !== null) {
                $this->streams[$id]->state |= Http2Stream::LOCAL_CLOSED;

                $trailers = $trailers->await();

                $headers = $this->encodeHeaders($trailers->getHeaders());

                if (\strlen($headers) > $this->maxFrameSize) {
                    $split = \str_split($headers, $this->maxFrameSize);
                    $headers = \array_shift($split);
                    async(fn () => $this->writeFrame($headers, Http2Parser::HEADERS, Http2Parser::NO_FLAG,
                        $id))->ignore();

                    $headers = \array_pop($split);
                    foreach ($split as $msgPart) {
                        async(fn () => $this->writeFrame($msgPart, Http2Parser::CONTINUATION, Http2Parser::NO_FLAG,
                            $id))->ignore();
                    }
                    async(fn () => $this->writeFrame($headers, Http2Parser::CONTINUATION,
                        Http2Parser::END_HEADERS | Http2Parser::END_STREAM, $id))->await();
                } else {
                    $this->writeFrame($headers, Http2Parser::HEADERS,
                        Http2Parser::END_HEADERS | Http2Parser::END_STREAM, $id);
                }
            }
        } catch (ClientException $exception) {
            $error = $exception->getCode() ?? Http2Parser::CANCEL; // Set error code to be used in finally below.
        } finally {
            if (!isset($this->streams[$id])) {
                return;
            }

            if ($chunk !== null) {
                if (isset($buffer) && $buffer !== "") {
                    $this->writeData($buffer, $id);
                }
                $error = $error ?? Http2Parser::INTERNAL_ERROR;
                $this->writeFrame(\pack("N", $error), Http2Parser::RST_STREAM, Http2Parser::NO_FLAG, $id);
                $this->releaseStream($id, $exception ?? new ClientException($this->client, "Stream error", $error));
                return;
            }

            if ($this->streams[$id]->state & Http2Stream::REMOTE_CLOSED) {
                $this->releaseStream($id);
            }
        }
    }

    /**
     * @param int|null $lastId ID of last processed frame. Null to use the last opened frame ID or 0 if no streams have
     *     been opened.
     */
    private function shutdown(?int $lastId = null, ?\Throwable $reason = null): void
    {
        $this->stopping = true;

        try {
            $futures = [];
            foreach ($this->streams as $id => $stream) {
                if ($lastId && $id > $lastId) {
                    break;
                }

                if ($stream->pendingResponse) {
                    $futures[] = $stream->pendingResponse;
                }
            }

            $code = $reason ? $reason->getCode() : Http2Parser::GRACEFUL_SHUTDOWN;
            $lastId ??= ($id ?? 0);
            $this->writeFrame(\pack("NN", $lastId, $code), Http2Parser::GOAWAY, Http2Parser::NO_FLAG);

            Future\await($futures);

            $futures = [];
            foreach ($this->streams as $id => $stream) {
                if ($lastId && $id > $lastId) {
                    break;
                }

                if ($stream->pendingWrite) {
                    $futures[] = $stream->pendingWrite;
                }
            }

            Future\await($futures);
        } catch (StreamException) {
            // ignore if no longer writable
        } finally {
            if (!empty($this->streams)) {
                $exception = new ClientException($this->client, $reason?->getMessage() ?? "", $code, $reason);
                foreach ($this->streams as $id => $stream) {
                    $this->releaseStream($id, $exception);
                }
            }

            // otherwise caller is still in process of initializing this driver
            if (isset($this->readableStream)) {
                $this->client->close();
                $this->readableStream->close();
                $this->writableStream->close();
            }
        }
    }

    private function sendPushPromise(Request $request, int $streamId, Push $push): void
    {
        $requestUri = $request->getUri();
        $pushUri = $push->getUri();
        $path = $pushUri->getPath();

        if (($path[0] ?? "/") !== "/") { // Relative Path
            $pushUri = $requestUri // Base push URI from original request URI.
                ->withPath($requestUri->getPath() . "/" . $path)
                ->withQuery($pushUri->getQuery());
        }

        if ($pushUri->getAuthority() === '') {
            $pushUri = $pushUri // If push URI did not provide a host, use original request URI.
                ->withHost($requestUri->getHost())
                ->withPort($requestUri->getPort());
        }

        $url = (string) $pushUri;

        if (isset($this->pushCache[$url])) {
            return; // Resource already pushed to this client.
        }

        $this->pushCache[$url] = $streamId;

        $path = $pushUri->getPath();
        if ($query = $pushUri->getQuery()) {
            $path .= "?" . $query;
        }

        $headers = \array_merge(
            \array_intersect_key($request->getHeaders(), self::PUSH_PROMISE_INTERSECT), // Uses only select headers
            $push->getHeaders() // Overwrites request headers with those defined in push.
        );

        // $id is the new stream ID for the pushed response, $streamId is the original request stream ID.
        $id = $this->localStreamId += 2; // Server initiated stream IDs must be even.
        $this->remoteStreamId = \max($id, $this->remoteStreamId);

        $request = new Request($this->client, "GET", $pushUri, $headers, "", "2");

        $this->streams[$id] = $stream = new Http2Stream(
            0, // No data will be incoming on this stream.
            $this->initialWindowSize,
            $this->initialWindowSize,
            Http2Stream::RESERVED | Http2Stream::REMOTE_CLOSED
        );

        $this->streamIdMap[\spl_object_hash($request)] = $id;

        $headers = \array_merge([
            ":authority" => [$pushUri->getAuthority()],
            ":scheme" => [$pushUri->getScheme()],
            ":path" => [$path],
            ":method" => ["GET"],
        ], $headers);

        $headers = \pack("N", $id) . $this->encodeHeaders($headers);

        if (\strlen($headers) >= $this->maxFrameSize) {
            $split = \str_split($headers, $this->maxFrameSize);
            $headers = \array_shift($split);
            async(fn () => $this->writeFrame($headers, Http2Parser::PUSH_PROMISE, Http2Parser::NO_FLAG,
                $streamId))->ignore();

            $headers = \array_pop($split);
            foreach ($split as $msgPart) {
                async(fn () => $this->writeFrame($msgPart, Http2Parser::CONTINUATION, Http2Parser::NO_FLAG,
                    $id))->ignore();
            }
            async(fn () => $this->writeFrame($headers, Http2Parser::CONTINUATION, Http2Parser::END_HEADERS,
                $id))->await();
        } else {
            $this->writeFrame($headers, Http2Parser::PUSH_PROMISE, Http2Parser::END_HEADERS, $streamId);
        }

        $stream->pendingResponse = async($this->handleRequest(...), $request);
    }

    private function writeFrame(string $data, int $type, int $flags, int $stream = 0): void
    {
        \assert(Http2Parser::logDebugFrame('send', $type, $flags, $stream, \strlen($data)));

        $this->writableStream->write(\pack("NcN", (\strlen($data) << 8) | ($type & 0xff), $flags, $stream) . $data);
    }

    private function writeData(string $data, int $id): void
    {
        \assert(isset($this->streams[$id]), "The stream was closed");

        $this->streams[$id]->buffer .= $data;

        $this->writeBufferedData($id);
    }

    private function writeBufferedData(int $id): void
    {
        \assert(isset($this->streams[$id]), "The stream was closed");

        $stream = $this->streams[$id];
        $delta = \min($this->clientWindow, $stream->clientWindow);
        $length = \strlen($stream->buffer);

        // TODO: $this->client->updateExpirationTime(\time() + $this->options->getHttp2Timeout());

        if ($delta >= $length) {
            $this->clientWindow -= $length;

            if ($length > $this->maxFrameSize) {
                $split = \str_split($stream->buffer, $this->maxFrameSize);
                $stream->buffer = \array_pop($split);
                foreach ($split as $part) {
                    $this->writeFrame($part, Http2Parser::DATA, Http2Parser::NO_FLAG, $id);
                }
            }

            if ($stream->state & Http2Stream::LOCAL_CLOSED) {
                $this->writeFrame($stream->buffer, Http2Parser::DATA, Http2Parser::END_STREAM, $id);
            } else {
                $this->writeFrame($stream->buffer, Http2Parser::DATA, Http2Parser::NO_FLAG, $id);
            }

            $stream->clientWindow -= $length;
            $stream->buffer = "";

            if ($stream->deferred) {
                $stream->deferred->complete();
                $stream->deferred = null;
            }

            return;
        }

        if ($delta > 0) {
            $data = $stream->buffer;
            $end = $delta - $this->maxFrameSize;

            $stream->clientWindow -= $delta;
            $this->clientWindow -= $delta;

            for ($off = 0; $off < $end; $off += $this->maxFrameSize) {
                $this->writeFrame(\substr($data, $off, $this->maxFrameSize), Http2Parser::DATA, Http2Parser::NO_FLAG,
                    $id);
            }

            $this->writeFrame(\substr($data, $off, $delta - $off), Http2Parser::DATA, Http2Parser::NO_FLAG, $id);

            $stream->buffer = \substr($data, $delta);
        }

        if ($stream->deferred === null) {
            $stream->deferred = new DeferredFuture;
        }

        $stream->deferred->getFuture()->await();
    }

    private function releaseStream(int $id, ClientException $exception = null): void
    {
        \assert(isset($this->streams[$id]), "Tried to release a non-existent stream");

        if (isset($this->bodyQueues[$id])) {
            $queue = $this->bodyQueues[$id];
            unset($this->bodyQueues[$id]);
            $queue->error($exception ?? new ClientException($this->client, "Client disconnected", Http2Parser::CANCEL));
        }

        if (isset($this->trailerDeferreds[$id])) {
            $deferred = $this->trailerDeferreds[$id];
            unset($this->trailerDeferreds[$id]);
            $deferred->error($exception ?? new ClientException($this->client, "Client disconnected",
                    Http2Parser::CANCEL));
        }

        unset($this->streams[$id]);

        if ($id & 1) { // Client-initiated stream.
            $this->remainingStreams++;
        }
    }

    private function readPreface(): string
    {
        $buffer = $this->readableStream->read();
        while (\strlen($buffer) < \strlen(Http2Parser::PREFACE)) {
            $buffer .= $this->readableStream->read();
        }

        if (!\str_starts_with($buffer, Http2Parser::PREFACE)) {
            throw new Http2ConnectionException("Invalid preface", Http2Parser::PROTOCOL_ERROR);
        }

        $buffer = \substr($buffer, \strlen(Http2Parser::PREFACE));

        if ($this->settings === null) {
            // Initial settings frame, delayed until after the preface is read for non-upgraded connections.
            $this->writeFrame(
                \pack(
                    "nNnNnNnN",
                    Http2Parser::INITIAL_WINDOW_SIZE,
                    $this->options->getBodySizeLimit(),
                    Http2Parser::MAX_CONCURRENT_STREAMS,
                    $this->options->getConcurrentStreamLimit(),
                    Http2Parser::MAX_HEADER_LIST_SIZE,
                    $this->options->getHeaderSizeLimit(),
                    Http2Parser::MAX_FRAME_SIZE,
                    self::DEFAULT_MAX_FRAME_SIZE
                ),
                Http2Parser::SETTINGS,
                Http2Parser::NO_FLAG
            );
        }

        return $buffer;
    }

    private function sendBufferedData(): void
    {
        foreach ($this->streams as $id => $stream) {
            if ($this->clientWindow <= 0) {
                return;
            }

            if ($stream->buffer === '' || $stream->clientWindow <= 0) {
                continue;
            }

            $this->writeBufferedData($id);
        }
    }

    private function encodeHeaders(array $headers): string
    {
        $input = [];

        foreach ($headers as $field => $values) {
            $values = (array) $values;

            foreach ($values as $value) {
                $input[] = [(string) $field, (string) $value];
            }
        }

        return $this->hpack->encode($input);
    }

    public function handlePong(string $data): void
    {
        // Ignored
    }

    public function handlePing(string $data): void
    {
        if (!$this->pinged) {
            // Ensure there are a few extra seconds for request after first ping.
            // TODO: $this->client->updateExpirationTime(
            // TODO:     \max($this->client->getExpirationTime(), \time() + 5)
            // TODO: );
        }

        $this->pinged++;

        if ($this->pinged > 5) {
            $this->handleConnectionException(
                new Http2ConnectionException('Too many pings', Http2Parser::ENHANCE_YOUR_CALM)
            );
        } else {
            $this->writeFrame($data, Http2Parser::PING, Http2Parser::ACK);
        }
    }

    public function handleShutdown(int $lastId, int $error): void
    {
        $message = \sprintf(
            "Received GOAWAY frame from %s with error code %d",
            $this->client->getRemoteAddress(),
            $error
        );

        if ($error !== Http2Parser::GRACEFUL_SHUTDOWN) {
            $this->logger->notice($message);
        }

        $this->shutdown($lastId, new Http2ConnectionException($message, $error));
    }

    public function handleStreamWindowIncrement(int $streamId, int $windowSize): void
    {
        if ($streamId > $this->remoteStreamId) {
            throw new Http2ConnectionException(
                "Stream ID does not exist",
                Http2Parser::PROTOCOL_ERROR
            );
        }

        if (!isset($this->streams[$streamId])) {
            return;
        }

        $stream = $this->streams[$streamId];

        if ($stream->clientWindow + $windowSize > 2147483647) {
            throw new Http2StreamException(
                "Current window size plus new window exceeds maximum size",
                $streamId,
                Http2Parser::FLOW_CONTROL_ERROR
            );
        }

        $stream->clientWindow += $windowSize;

        EventLoop::defer($this->sendBufferedData(...));
    }

    public function handleConnectionWindowIncrement(int $windowSize): void
    {
        if ($this->clientWindow + $windowSize > 2147483647) {
            throw new Http2ConnectionException(
                "Current window size plus new window exceeds maximum size",
                Http2Parser::FLOW_CONTROL_ERROR
            );
        }

        $this->clientWindow += $windowSize;

        EventLoop::defer($this->sendBufferedData(...));
    }

    public function handleHeaders(int $streamId, array $pseudo, array $headers, bool $ended): void
    {
        foreach ($pseudo as $name => $value) {
            if (!isset(Http2Parser::KNOWN_REQUEST_PSEUDO_HEADERS[$name])) {
                throw new Http2StreamException(
                    "Invalid pseudo header",
                    $streamId,
                    Http2Parser::PROTOCOL_ERROR
                );
            }
        }

        if (isset($this->streams[$streamId])) {
            $stream = $this->streams[$streamId];

            if ($stream->state & Http2Stream::REMOTE_CLOSED) {
                throw new Http2StreamException(
                    "Stream remote closed",
                    $streamId,
                    Http2Parser::STREAM_CLOSED
                );
            }
        } else {
            if (!($streamId & 1) || $this->remainingStreams-- <= 0 || $streamId <= $this->remoteStreamId) {
                throw new Http2ConnectionException(
                    "Invalid stream ID",
                    Http2Parser::PROTOCOL_ERROR
                );
            }

            $stream = $this->streams[$streamId] = new Http2Stream(
                $this->options->getBodySizeLimit(),
                $this->initialWindowSize,
                $this->initialWindowSize
            );
        }

        // Header frames can be received on previously opened streams (trailer headers).
        $this->remoteStreamId = \max($streamId, $this->remoteStreamId);

        // TODO: $this->client->updateExpirationTime(\time() + $this->options->getHttp2Timeout());

        if (isset($this->trailerDeferreds[$streamId]) && $stream->state & Http2Stream::RESERVED) {
            if (!$ended) {
                throw new Http2ConnectionException(
                    "Trailers must end the stream",
                    Http2Parser::PROTOCOL_ERROR
                );
            }

            // Trailers must not contain pseudo-headers.
            if (!empty($pseudo)) {
                throw new Http2StreamException(
                    "Trailers must not contain pseudo headers",
                    $streamId,
                    Http2Parser::PROTOCOL_ERROR
                );
            }

            // Trailers must not contain any disallowed fields.
            if (\array_intersect_key($headers, Trailers::DISALLOWED_TRAILERS)) {
                throw new Http2StreamException(
                    "Disallowed trailer field name",
                    $streamId,
                    Http2Parser::PROTOCOL_ERROR
                );
            }

            $deferred = $this->trailerDeferreds[$streamId];
            $queue = $this->bodyQueues[$streamId];

            unset($this->bodyQueues[$streamId], $this->trailerDeferreds[$streamId]);

            $queue->complete();
            $deferred->complete($headers);

            return;
        }

        if ($stream->state & Http2Stream::RESERVED) {
            throw new Http2StreamException(
                "Stream already reserved",
                $streamId,
                Http2Parser::PROTOCOL_ERROR
            );
        }

        $stream->state |= Http2Stream::RESERVED;

        if ($this->stopping) {
            throw new Http2StreamException("Shutting down", $streamId, Http2Parser::REFUSED_STREAM);
        }

        if (!isset($pseudo[":method"], $pseudo[":path"], $pseudo[":scheme"], $pseudo[":authority"])
            || isset($headers["connection"])
            || $pseudo[":path"] === ''
            || (isset($headers["te"]) && \implode($headers["te"]) !== "trailers")
        ) {
            throw new Http2StreamException(
                "Invalid header values",
                $streamId,
                Http2Parser::PROTOCOL_ERROR
            );
        }

        $method = $pseudo[":method"];
        $target = $pseudo[":path"];
        $scheme = $pseudo[":scheme"];
        $host = $pseudo[":authority"];
        $query = null;

        if (!\preg_match("#^([A-Z\d.\-]+|\[[\d:]+])(?::([1-9]\d*))?$#i", $host, $matches)) {
            throw new Http2StreamException(
                "Invalid authority (host) name",
                $streamId,
                Http2Parser::PROTOCOL_ERROR
            );
        }

        $host = $matches[1];
        $port = isset($matches[2]) ? (int) $matches[2] : $this->client->getLocalAddress()->getPort();

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
                "host" => $host,
                "port" => $port,
                "path" => $target,
                "query" => $query,
            ]);
        } catch (Uri\Contracts\UriException $exception) {
            throw new Http2ConnectionException(
                "Invalid request URI",
                Http2Parser::PROTOCOL_ERROR
            );
        }

        $this->pinged = 0; // Reset ping count when a request is received.

        if ($ended) {
            $request = new Request(
                $this->client,
                $method,
                $uri,
                $headers,
                "",
                "2"
            );

            $this->streamIdMap[\spl_object_hash($request)] = $streamId;
            $stream->pendingResponse = async($this->handleRequest(...), $request);

            return;
        }

        $this->trailerDeferreds[$streamId] = new DeferredFuture;
        $this->bodyQueues[$streamId] = new Queue();

        $body = new RequestBody(
            new ReadableIterableStream($this->bodyQueues[$streamId]->pipe()),
            function (int $bodySize) use ($streamId) {
                if (!isset($this->streams[$streamId], $this->bodyQueues[$streamId])) {
                    return;
                }

                if ($this->streams[$streamId]->bodySizeLimit >= $bodySize) {
                    return;
                }

                $this->streams[$streamId]->bodySizeLimit = $bodySize;
            }
        );

        $bodySizeLimit = $this->options->getBodySizeLimit();

        if ($this->serverWindow <= $bodySizeLimit >> 1) {
            $increment = $bodySizeLimit - $this->serverWindow;
            $this->serverWindow = $bodySizeLimit;
            $this->writeFrame(\pack("N", $increment), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG);
        }

        if (isset($headers["content-length"])) {
            if (isset($headers["content-length"][1])) {
                throw new Http2StreamException(
                    "Received multiple content-length headers",
                    $streamId,
                    Http2Parser::PROTOCOL_ERROR
                );
            }

            $contentLength = $headers["content-length"][0];
            if (!\preg_match('/^0|[1-9]\d*$/', $contentLength)) {
                throw new Http2StreamException(
                    "Invalid content-length header value",
                    $streamId,
                    Http2Parser::PROTOCOL_ERROR
                );
            }

            $stream->expectedLength = (int) $contentLength;
        }

        try {
            $trailers = new Trailers(
                $this->trailerDeferreds[$streamId]->getFuture(),
                isset($headers['trailers'])
                    ? \array_map('trim', \explode(',', \implode(',', $headers['trailers'])))
                    : []
            );
        } catch (InvalidHeaderException $exception) {
            throw new Http2StreamException(
                "Invalid headers field in promises trailers",
                $streamId,
                Http2Parser::PROTOCOL_ERROR,
                $exception
            );
        }

        $request = new Request(
            $this->client,
            $method,
            $uri,
            $headers,
            $body,
            "2",
            $trailers
        );

        $this->streamIdMap[\spl_object_hash($request)] = $streamId;
        $stream->pendingResponse = async($this->handleRequest(...), $request);
    }

    public function handleData(int $streamId, string $data): void
    {
        $length = \strlen($data);
        // TODO: $this->client->updateExpirationTime(\time() + $this->options->getHttp2Timeout());

        if (!isset($this->streams[$streamId], $this->bodyQueues[$streamId], $this->trailerDeferreds[$streamId])) {
            if ($streamId > 0 && $streamId <= $this->remoteStreamId) {
                throw new Http2StreamException(
                    "Stream closed",
                    $streamId,
                    Http2Parser::STREAM_CLOSED
                );
            }

            throw new Http2ConnectionException(
                "Invalid stream ID",
                Http2Parser::PROTOCOL_ERROR
            );
        }

        $stream = $this->streams[$streamId];

        if ($stream->state & Http2Stream::REMOTE_CLOSED) {
            throw new Http2StreamException(
                "Stream remote closed",
                $streamId,
                Http2Parser::PROTOCOL_ERROR
            );
        }

        if (!$length) {
            return;
        }

        $this->serverWindow -= $length;
        $stream->serverWindow -= $length;
        $stream->receivedByteCount += $length;

        if ($stream->receivedByteCount > $stream->bodySizeLimit) {
            throw new Http2StreamException(
                "Max body size exceeded",
                $streamId,
                Http2Parser::CANCEL
            );
        }

        if ($this->serverWindow <= self::MINIMUM_WINDOW) {
            $this->serverWindow += self::MAX_INCREMENT;
            $this->writeFrame(\pack("N", self::MAX_INCREMENT), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG);
        }

        if ($stream->expectedLength !== null) {
            $stream->expectedLength -= $length;
        }

        $future = $this->bodyQueues[$streamId]->pushAsync($data);
        $future->ignore();

        if ($stream->serverWindow <= self::MINIMUM_WINDOW) {
            EventLoop::queue(function () use ($future, $stream, $streamId): void {
                try {
                    $future->await();
                } catch (\Throwable) {
                    return;
                }

                if (!isset($this->streams[$streamId])) {
                    return;
                }

                $stream = $this->streams[$streamId];

                if ($stream->state & Http2Stream::REMOTE_CLOSED
                    || $stream->serverWindow > self::MINIMUM_WINDOW
                ) {
                    return;
                }

                $increment = \min(
                    $stream->bodySizeLimit + 1 - $stream->receivedByteCount - $stream->serverWindow,
                    self::MAX_INCREMENT
                );

                if ($increment <= 0) {
                    return;
                }

                $stream->serverWindow += $increment;

                $this->writeFrame(
                    \pack("N", $increment),
                    Http2Parser::WINDOW_UPDATE,
                    Http2Parser::NO_FLAG,
                    $streamId
                );
            });
        }
    }

    public function handleStreamEnd(int $streamId): void
    {
        if (!isset($this->streams[$streamId])) {
            return; // Stream already closed locally.
        }

        $stream = $this->streams[$streamId];

        $stream->state |= Http2Stream::REMOTE_CLOSED;

        if ($stream->expectedLength) {
            throw new Http2StreamException(
                "Body length does not match content-length header",
                $streamId,
                Http2Parser::PROTOCOL_ERROR
            );
        }

        try {
            if (!isset($this->bodyQueues[$streamId], $this->trailerDeferreds[$streamId])) {
                return; // Stream closed after emitting body fragment.
            }

            $deferred = $this->trailerDeferreds[$streamId];
            $queue = $this->bodyQueues[$streamId];

            unset($this->bodyQueues[$streamId], $this->trailerDeferreds[$streamId]);

            $queue->complete();
            $deferred->complete([]);
        } finally {
            if (!isset($this->streams[$streamId])) {
                return; // Stream may have closed after resolving body emitter or trailers deferred.
            }

            // Close stream only if also locally closed and there is no buffer remaining to write.
            if ($stream->state & Http2Stream::LOCAL_CLOSED && $stream->buffer === "") {
                $this->releaseStream($streamId);
            }
        }
    }

    public function handlePushPromise(int $streamId, int $pushId, array $pseudo, array $headers): void
    {
        throw new Http2ConnectionException(
            "Client should not send push promise frames",
            Http2Parser::PROTOCOL_ERROR
        );
    }

    public function handlePriority(int $streamId, int $parentId, int $weight): void
    {
        if (!isset($this->streams[$streamId])) {
            if (!($streamId & 1) || $this->remainingStreams-- <= 0) {
                throw new Http2ConnectionException(
                    "Invalid stream ID",
                    Http2Parser::PROTOCOL_ERROR
                );
            }

            if ($streamId <= $this->remoteStreamId) {
                return; // Ignore priority frames on closed streams.
            }

            // Open a new stream if the ID has not been seen before, but do not set
            // $this->remoteStreamId. That will be set once the headers are received.
            $this->streams[$streamId] = new Http2Stream(
                $this->options->getBodySizeLimit(),
                $this->initialWindowSize,
                $this->initialWindowSize
            );
        }

        $stream = $this->streams[$streamId];

        $stream->dependency = $parentId;
        $stream->weight = $weight;
    }

    public function handleStreamReset(int $streamId, int $errorCode): void
    {
        if ($streamId > $this->remoteStreamId) {
            throw new Http2ConnectionException(
                "Invalid stream ID",
                Http2Parser::PROTOCOL_ERROR
            );
        }

        if (isset($this->streams[$streamId])) {
            $exception = new Http2StreamException("Stream reset", $streamId, $errorCode);

            $this->releaseStream(
                $streamId,
                new ClientException($this->client, "Client closed stream", $errorCode, $exception)
            );
        }
    }

    public function handleSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            switch ($key) {
                case Http2Parser::INITIAL_WINDOW_SIZE:
                    if ($value > 2147483647) { // (1 << 31) - 1
                        throw new Http2ConnectionException("Invalid window size", Http2Parser::FLOW_CONTROL_ERROR);
                    }

                    $priorWindowSize = $this->initialWindowSize;
                    $this->initialWindowSize = $value;
                    $difference = $this->initialWindowSize - $priorWindowSize;

                    foreach ($this->streams as $stream) {
                        $stream->clientWindow += $difference;
                    }

                    // Settings ACK should be sent before HEADER or DATA frames.
                    EventLoop::defer($this->sendBufferedData(...));
                    break;

                case Http2Parser::ENABLE_PUSH:
                    if ($value & ~1) {
                        throw new Http2ConnectionException(
                            "Invalid push promise toggle value",
                            Http2Parser::PROTOCOL_ERROR
                        );
                    }

                    $this->allowsPush = ((bool) $value) && $this->options->isPushEnabled();
                    break;

                case Http2Parser::MAX_FRAME_SIZE:
                    if ($value < 1 << 14 || $value >= 1 << 24) {
                        throw new Http2ConnectionException("Invalid max frame size", Http2Parser::PROTOCOL_ERROR);
                    }

                    $this->maxFrameSize = $value;
                    break;

                case Http2Parser::HEADER_TABLE_SIZE:
                case Http2Parser::MAX_HEADER_LIST_SIZE:
                case Http2Parser::MAX_CONCURRENT_STREAMS:
                    break; // @TODO Respect these settings from the client.

                default:
                    break; // Unknown setting, ignore (6.5.2).
            }
        }

        $this->writeFrame("", Http2Parser::SETTINGS, Http2Parser::ACK);
    }

    public function handleStreamException(Http2StreamException $exception): void
    {
        $streamId = $exception->getStreamId();
        $errorCode = $exception->getCode();

        $this->writeFrame(\pack("N", $errorCode), Http2Parser::RST_STREAM, Http2Parser::NO_FLAG, $streamId);

        if (isset($this->streams[$streamId])) {
            $this->releaseStream($streamId, new ClientException($this->client, "HTTP/2 stream error", 0, $exception));
        }
    }

    public function handleConnectionException(Http2ConnectionException $exception): void
    {
        $this->logger->notice("HTTP/2 connection error for client {address}: {message}", [
            'address' => $this->client->getRemoteAddress()->toString(),
            'message' => $exception->getMessage(),
        ]);

        $this->shutdown(
            null,
            new ClientException($this->client, "HTTP/2 connection error", $exception->getCode(), $exception)
        );
    }

    public function getApplicationLayerProtocols(): array
    {
        return ['h2'];
    }
}
