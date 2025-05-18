<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\HPack;
use Amp\Http\Http2\Http2ConnectionException;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\Http2\Http2Processor;
use Amp\Http\Http2\Http2StreamException;
use Amp\Http\HttpStatus;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Internal\AbstractHttpDriver;
use Amp\Http\Server\Driver\Internal\Http2Stream;
use Amp\Http\Server\Driver\Internal\StreamTimeoutTracker;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Push;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Pipeline\Queue;
use Amp\Socket\InternetAddress;
use League\Uri;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Http\formatDateHeader;

final class Http2Driver extends AbstractHttpDriver implements Http2Processor
{
    public const DEFAULT_CONCURRENT_STREAM_LIMIT = 100;

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

    private Client $client;
    private ReadableStream $readableStream;
    private WritableStream $writableStream;

    private StreamTimeoutTracker $timeoutTracker;

    private int $serverWindow = self::DEFAULT_WINDOW_SIZE;

    private int $clientWindow = self::DEFAULT_WINDOW_SIZE;

    private int $initialWindowSize = self::DEFAULT_WINDOW_SIZE;

    /** @var positive-int */
    private int $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE;

    private bool $allowsPush;

    /** @var int Last used local stream ID. */
    private int $localStreamId = 0;

    /** @var int Last used remote stream ID. */
    private int $remoteStreamId = 0;

    /** @var array<int, Http2Stream> */
    private array $streams = [];

    /** @var \WeakMap<Request, int> Map of Request objects to stream IDs. */
    private \WeakMap $streamIdMap;

    /** @var array<string, int> Map of URLs pushed on this connection. */
    private array $pushCache = [];

    /** @var array<int, DeferredFuture> */
    private array $trailerDeferreds = [];

    /** @var array<int, Queue> */
    private array $bodyQueues = [];

    /** @var int Number of streams that may be opened. */
    private int $remainingStreams;

    private bool $stopping = false;

    private int $pinged = 0;

    private readonly HPack $hpack;

    public function __construct(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        private readonly int $streamTimeout = self::DEFAULT_STREAM_TIMEOUT,
        private readonly int $connectionTimeout = self::DEFAULT_CONNECTION_TIMEOUT,
        private readonly int $headerSizeLimit = self::DEFAULT_HEADER_SIZE_LIMIT,
        private readonly int $bodySizeLimit = self::DEFAULT_BODY_SIZE_LIMIT,
        private readonly int $concurrentStreamLimit = self::DEFAULT_CONCURRENT_STREAM_LIMIT,
        private readonly bool $pushEnabled = true,
        private readonly ?string $settings = null,
    ) {
        parent::__construct($requestHandler, $errorHandler, $logger);

        $this->remainingStreams = $concurrentStreamLimit;
        $this->allowsPush = $pushEnabled;

        $this->hpack = new HPack();

        /** @var \WeakMap<Request, int> */
        $this->streamIdMap = new \WeakMap();
    }

    public function handleClient(
        Client $client,
        ReadableStream $readableStream,
        WritableStream $writableStream,
    ): void {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(!isset($this->client), "The driver has already been setup");

        $this->client = $client;
        $this->readableStream = $readableStream;
        $this->writableStream = $writableStream;

        $this->timeoutTracker = new StreamTimeoutTracker(
            $this->client,
            self::getTimeoutQueue(),
            $this->connectionTimeout,
            $this->streamTimeout,
            fn () => $this->shutdown(new ClientException($this->client, 'Shutting down connection due to inactivity')),
        );

        $this->processClientInput();
    }

    /**
     * Provide separate functions for Http2Driver initialization:
     * The Http1Driver may still be in process of reading a possible request body.
     * As we want to be able to already start sending HTTP/2 frames before the whole request body has been read,
     * we need to initialize writing early. Hence, we need a separate function for starting reading on the stream.
     */
    public function initializeWriting(
        Client $client,
        WritableStream $writableStream,
    ): void {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(!isset($this->client), "The driver has already been setup");

        $this->client = $client;
        $this->writableStream = $writableStream;

        $this->timeoutTracker = new StreamTimeoutTracker(
            $this->client,
            self::getTimeoutQueue(),
            $this->connectionTimeout,
            $this->streamTimeout,
            fn () => $this->shutdown(new ClientException($this->client, 'Shutting down connection due to inactivity')),
        );

        if ($this->settings !== null) {
            // Upgraded connections automatically assume an initial stream with ID 1.
            // No data will be incoming on this stream, so body size of 0.
            $this->createStream(1, 0, Http2Stream::RESERVED | Http2Stream::REMOTE_CLOSED);
            $this->remoteStreamId = \max(1, $this->remoteStreamId);
            $this->remainingStreams--;

            // Initial settings frame, sent immediately for upgraded connections.
            $this->writeFrame(
                \pack(
                    "nNnNnNnN",
                    Http2Parser::INITIAL_WINDOW_SIZE,
                    self::DEFAULT_WINDOW_SIZE,
                    Http2Parser::MAX_CONCURRENT_STREAMS,
                    $this->concurrentStreamLimit,
                    Http2Parser::MAX_HEADER_LIST_SIZE,
                    $this->headerSizeLimit,
                    Http2Parser::MAX_FRAME_SIZE,
                    self::DEFAULT_MAX_FRAME_SIZE
                ),
                Http2Parser::SETTINGS,
                Http2Parser::NO_FLAG
            );
        }
    }

    public function handleClientWithBuffer(string $buffer, ReadableStream $readableStream): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(isset($this->client), "The driver has not been setup");

        $this->readableStream = $readableStream;

        $this->processClientInput($buffer);
    }

    private function processClientInput(?string $chunk = null): void
    {
        /** @psalm-suppress RedundantCondition */
        \assert($this->logger->debug(\sprintf(
            "Handling requests from %s #%d using HTTP/2 driver",
            $this->client->getRemoteAddress()->toString(),
            $this->client->getId(),
        )) || true);

        $parser = new Http2Parser($this, $this->hpack, $this->settings);

        try {
            $parser->push($chunk ?? $this->readPreface());

            while (null !== $chunk = $this->readableStream->read()) {
                $parser->push($chunk);
            }

            $this->shutdown();
        } catch (StreamException|Http2ConnectionException $exception) {
            $this->shutdown(new ClientException(
                $this->client,
                "Exception thrown when reading client input: " . $exception->getMessage(),
                $exception->getCode(),
                $exception,
            ));
        } finally {
            $parser->cancel();
        }
    }

    protected function write(Request $request, Response $response): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(isset($this->client), "The driver has not been setup");

        $streamId = $this->streamIdMap[$request] ?? 1; // Default ID of 1 for upgrade requests.

        if (!isset($this->streams[$streamId])) {
            return; // Client closed the stream or connection.
        }

        if ($streamId & 1) {
            $this->timeoutTracker->update($streamId);
        }

        $stream = $this->streams[$streamId]; // $this->streams[$streamId] may be unset in send().
        $deferred = new DeferredFuture;
        $stream->pendingWrite = $deferred->getFuture();
        $cancellation = $stream->deferredCancellation->getCancellation();

        try {
            $this->send($streamId, $response, $request, $cancellation);
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

    private function send(int $id, Response $response, Request $request, Cancellation $cancellation): void
    {
        $chunk = ""; // Required for the finally, not directly overwritten, even if your IDE says otherwise.

        $need = $response->getHeader("content-length");
        $wrote = 0;

        try {
            $status = $response->getStatus();

            if ($status < HttpStatus::OK) {
                $response->setStatus(HttpStatus::HTTP_VERSION_NOT_SUPPORTED);
                throw new ClientException(
                    $this->client,
                    "1xx response codes are not supported in HTTP/2",
                    Http2Parser::HTTP_1_1_REQUIRED
                );
            }

            if ($status === HttpStatus::HTTP_VERSION_NOT_SUPPORTED && $response->getHeader("upgrade")) {
                throw new ClientException(
                    $this->client,
                    "Upgrade requests require HTTP/1.1",
                    Http2Parser::HTTP_1_1_REQUIRED
                );
            }

            $headers = [
                ':status' => [$status],
                ...$response->getHeaders(),
                'date' => [formatDateHeader()],
            ];

            // Remove headers that are obsolete in HTTP/2.
            unset($headers["connection"], $headers["keep-alive"], $headers["transfer-encoding"]);

            $trailers = $response->getTrailers();

            if ($trailers !== null && !isset($headers["trailer"]) && ($fields = $trailers->getFields())) {
                $headers["trailer"] = [\implode(", ", $fields)];
            }

            foreach ($response->getPushes() as $push) {
                $headers["link"][] = "<{$push->getUri()}>; rel=preload";
                if ($this->allowsPush) {
                    $this->sendPushPromise($request, $id, $push);
                }
            }

            $this->writeHeaders($this->encodeHeaders($headers), Http2Parser::HEADERS, 0, $id);

            if ($request->getMethod() === "HEAD") {
                $this->streams[$id]->state |= Http2Stream::LOCAL_CLOSED;
                $this->writeData("", $id);
                $need = null;
                return;
            }

            $body = $response->getBody();
            $chunk = $body->read($cancellation);

            while ($chunk !== null) {
                // Stream may have been closed while waiting for body data.
                if (!isset($this->streams[$id])) {
                    return;
                }
                $wrote += \strlen($chunk);

                $this->writeData($chunk, $id);

                $chunk = $body->read($cancellation);
            }

            // Stream may have been closed while waiting for body data.
            if (!isset($this->streams[$id])) {
                return;
            }

            $this->streams[$id]->state |= Http2Stream::LOCAL_CLOSED;

            if ($trailers === null) {
                $this->writeData("", $id);
            } else {
                $trailers = $trailers->await($cancellation);

                // Stream may have been closed while writing final body chunk or headers.
                if (!isset($this->streams[$id])) {
                    return;
                }

                $this->writeHeaders(
                    $this->encodeHeaders($trailers->getHeaders()),
                    Http2Parser::HEADERS,
                    Http2Parser::END_STREAM,
                    $id,
                );
            }
        } catch (ClientException $exception) {
            $error = $exception->getCode() ?? Http2Parser::CANCEL; // Set error code to be used below.
        } catch (StreamException|CancelledException) {
            // Body stream threw or client disconnected, ignore and proceed to clean up below.
            $chunk = null;
        } catch (\Throwable $throwable) {
            // Will be rethrown after cleanup below.
        }

        // Cleanup outside finally block since the fiber may suspend to write RST_STREAM frame.
        try {
            /** @psalm-suppress ParadoxicalCondition Stream may be unset while awaiting above */
            if (!isset($this->streams[$id])) {
                return;
            }

            if ($chunk !== null || ($need !== null && $wrote !== (int) $need)) {
                $error ??= Http2Parser::INTERNAL_ERROR;
                $this->writeFrame(\pack("N", $error), Http2Parser::RST_STREAM, Http2Parser::NO_FLAG, $id);
                $this->releaseStream($id, $exception ?? new ClientException($this->client, "Stream error", $error));
                return;
            }

            if ($this->streams[$id]->state & Http2Stream::REMOTE_CLOSED) {
                $this->releaseStream($id);
            }
        } finally {
            if (isset($throwable)) {
                throw $throwable;
            }
        }
    }

    private function shutdown(?ClientException $reason = null): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;

        $previous = $reason?->getPrevious();
        $previous = $previous instanceof Http2ConnectionException ? $previous : null;

        $code = $previous?->getCode() ?? Http2Parser::GRACEFUL_SHUTDOWN;

        try {
            $futures = [];
            foreach ($this->streams as $id => $stream) {
                if ($id > $this->remoteStreamId) {
                    break;
                }

                if ($stream->pendingResponse) {
                    $futures[] = $stream->pendingResponse;
                }
            }

            $message = match ($code) {
                Http2Parser::PROTOCOL_ERROR,
                Http2Parser::FLOW_CONTROL_ERROR,
                Http2Parser::FRAME_SIZE_ERROR,
                Http2Parser::COMPRESSION_ERROR,
                Http2Parser::SETTINGS_TIMEOUT,
                Http2Parser::ENHANCE_YOUR_CALM => $previous?->getMessage(),
                default => null,
            };

            $this->writeFrame(
                \pack("NN", $this->remoteStreamId, $code) . $message,
                Http2Parser::GOAWAY,
                Http2Parser::NO_FLAG,
            );

            /** @psalm-suppress RedundantCondition */
            \assert($this->logger->debug(\sprintf(
                "Shutting down HTTP/2 client @ %s #%d; last-id: %d; reason: %s",
                $this->client->getRemoteAddress()->toString(),
                $this->client->getId(),
                $this->remoteStreamId,
                $reason?->getMessage() ?? "undefined",
            )) || true);

            Future\await($futures);

            $futures = [];
            foreach ($this->streams as $id => $stream) {
                if ($id > $this->remoteStreamId) {
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
                $reason ??= new ClientException($this->client, "Connection closed unexpectedly", Http2Parser::CANCEL);
                foreach ($this->streams as $id => $stream) {
                    $this->releaseStream($id, $reason);
                }
            }

            $this->client->close();
            $this->readableStream->close();
            $this->writableStream->close();
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

        $headers = [
            ...\array_intersect_key($request->getHeaders(), self::PUSH_PROMISE_INTERSECT), // Uses only select headers
            ...$push->getHeaders() // Overwrites request headers with those defined in push.
        ];

        // $id is the new stream ID for the pushed response, $streamId is the original request stream ID.
        $id = $this->localStreamId += 2; // Server initiated stream IDs must be even.

        $request = new Request($this->client, "GET", $pushUri, $headers, "", "2");

        // No data will be incoming on this stream.
        $stream = $this->createStream($id, 0, Http2Stream::RESERVED | Http2Stream::REMOTE_CLOSED);

        $this->streamIdMap[$request] = $id;

        $headers = [
            ":authority" => [$pushUri->getAuthority()],
            ":scheme" => [$pushUri->getScheme()],
            ":path" => [$path],
            ":method" => ["GET"],
            ...$headers,
        ];

        $this->writeHeaders(
            \pack("N", $id) . $this->encodeHeaders($headers),
            Http2Parser::PUSH_PROMISE,
            0,
            $streamId
        );

        $stream->pendingResponse = async($this->handleRequest(...), $request);
    }

    private function writeFrame(string $data, int $type, int $flags, int $stream = 0): void
    {
        $this->writableStream->write(Http2Parser::compileFrame($data, $type, $flags, $stream));
    }

    private function writeData(string $data, int $id): void
    {
        \assert(isset($this->streams[$id]), "The stream was closed");

        $this->streams[$id]->buffer .= $data;

        $this->writeBufferedData($id);
    }

    private function writeBufferedData(int $streamId): void
    {
        \assert(isset($this->streams[$streamId]), "The stream was closed");

        $stream = $this->streams[$streamId];
        $delta = \min($this->clientWindow, $stream->clientWindow);
        $length = \strlen($stream->buffer);

        if ($streamId & 1) {
            $this->timeoutTracker->update($streamId);
        }

        if ($delta >= $length) {
            $this->clientWindow -= $length;

            if ($length > $this->maxFrameSize) {
                $split = \str_split($stream->buffer, $this->maxFrameSize);
                $stream->buffer = \array_pop($split);
                foreach ($split as $part) {
                    $this->writeFrame($part, Http2Parser::DATA, Http2Parser::NO_FLAG, $streamId);
                }
            }

            if ($stream->state & Http2Stream::LOCAL_CLOSED) {
                $this->writeFrame($stream->buffer, Http2Parser::DATA, Http2Parser::END_STREAM, $streamId);
            } else {
                $this->writeFrame($stream->buffer, Http2Parser::DATA, Http2Parser::NO_FLAG, $streamId);
            }

            $stream->clientWindow -= $length;
            $stream->buffer = "";

            if ($stream->deferredFuture) {
                $stream->deferredFuture->complete();
                $stream->deferredFuture = null;
            }

            return;
        }

        if ($delta > 0) {
            $data = $stream->buffer;
            $end = $delta - $this->maxFrameSize;

            $stream->clientWindow -= $delta;
            $this->clientWindow -= $delta;

            for ($off = 0; $off < $end; $off += $this->maxFrameSize) {
                $this->writeFrame(
                    \substr($data, $off, $this->maxFrameSize),
                    Http2Parser::DATA,
                    Http2Parser::NO_FLAG,
                    $streamId
                );
            }

            $this->writeFrame(\substr($data, $off, $delta - $off), Http2Parser::DATA, Http2Parser::NO_FLAG, $streamId);

            $stream->buffer = \substr($data, $delta);
        }

        $stream->deferredFuture ??= new DeferredFuture;
        $stream->deferredFuture->getFuture()->await();
    }

    private function writeHeaders(string $headers, int $type, int $flags, int $id): void
    {
        $flags |= Http2Parser::END_HEADERS;

        if (\strlen($headers) > $this->maxFrameSize) {
            // Header frames must be sent as one contiguous block without frames from any other stream being
            // interleaved between due to HPack. See https://datatracker.ietf.org/doc/html/rfc7540#section-4.3
            $split = \str_split($headers, $this->maxFrameSize);
            $headers = \array_pop($split);

            $writeFrame = $this->writeFrame(...);
            foreach ($split as $part) {
                async($writeFrame, $part, $type, Http2Parser::NO_FLAG, $id)->ignore();
                $type = Http2Parser::CONTINUATION;
            }
            async($writeFrame, $headers, $type, $flags, $id)->await();

            return;
        }

        $this->writeFrame($headers, $type, $flags, $id);
    }

    private function createStream(int $id, int $bodySizeLimit, int $flags = Http2Stream::OPEN): Http2Stream
    {
        \assert(!isset($this->streams[$id]));

        if ($id & 1) {
            $this->timeoutTracker->insert(
                $id,
                fn (int $id) => $this->releaseStream(
                    $id,
                    new ClientException($this->client, "Closing stream due to inactivity"),
                ),
            );
        }

        return $this->streams[$id] = new Http2Stream(
            $bodySizeLimit,
            $this->initialWindowSize,
            $this->initialWindowSize,
            $flags,
        );
    }

    private function releaseStream(int $id, ?ClientException $exception = null): void
    {
        \assert(isset($this->streams[$id]), "Tried to release a non-existent stream");

        $this->streams[$id]->deferredCancellation->cancel();

        if ($id & 1) {
            $this->timeoutTracker->remove($id);
        }

        ($this->bodyQueues[$id] ?? null)?->error(
            $exception ??= new ClientException($this->client, "Client disconnected", Http2Parser::CANCEL)
        );

        ($this->trailerDeferreds[$id] ?? null)?->error(
            $exception ?? new ClientException($this->client, "Client disconnected", Http2Parser::CANCEL)
        );

        unset($this->streams[$id], $this->bodyQueues[$id], $this->trailerDeferreds[$id]);

        if ($id & 1) { // Client-initiated stream.
            $this->remainingStreams++;
        }
    }

    private function readPreface(): string
    {
        $buffer = $this->readableStream->read();
        if ($buffer === null) {
            throw new Http2ConnectionException("Invalid preface", Http2Parser::PROTOCOL_ERROR);
        }

        while (\strlen($buffer) < \strlen(Http2Parser::PREFACE)) {
            $chunk = $this->readableStream->read();
            if ($chunk === null) {
                throw new Http2ConnectionException("Invalid preface", Http2Parser::PROTOCOL_ERROR);
            }

            $buffer .= $chunk;
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
                    self::DEFAULT_WINDOW_SIZE,
                    Http2Parser::MAX_CONCURRENT_STREAMS,
                    $this->concurrentStreamLimit,
                    Http2Parser::MAX_HEADER_LIST_SIZE,
                    $this->headerSizeLimit,
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

            try {
                $this->writeBufferedData($id);
            } catch (StreamException) {
                return; // Socket closed while writing buffered data.
            }
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
            $this->timeoutTracker->ping(5);
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

    public function handleShutdown(int $lastId, int $error, string $message): void
    {
        $message = \sprintf(
            "Received GOAWAY frame from %s with error code %d and message '%s'",
            $this->client->getRemoteAddress()->toString(),
            $error,
            $message,
        );

        if ($error !== Http2Parser::GRACEFUL_SHUTDOWN) {
            $this->logger->notice($message);
        }

        $this->shutdown(new ClientException(
            $this->client,
            "Client closed HTTP/2 connection",
            $error,
            new Http2ConnectionException($message, $error),
        ));
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

    public function handleHeaders(int $streamId, array $pseudo, array $headers, bool $streamEnded): void
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

            $stream = $this->createStream($streamId, $this->bodySizeLimit);
        }

        // Header frames can be received on previously opened streams (trailer headers).
        $this->remoteStreamId = \max($streamId, $this->remoteStreamId);

        if (isset($this->trailerDeferreds[$streamId]) && $stream->state & Http2Stream::RESERVED) {
            if (!$streamEnded) {
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

            $this->timeoutTracker->suspend($streamId);

            $queue->complete();
            $deferred->complete($headers);

            return;
        }

        $this->timeoutTracker->update($streamId);

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

        [':method' => $method, ':path' => $target, ':scheme' => $scheme, ':authority' => $host] = $pseudo;
        $query = null;

        if (!\preg_match(self::HOST_HEADER_REGEX, $host, $matches)) {
            throw new Http2StreamException(
                "Invalid authority (host) name",
                $streamId,
                Http2Parser::PROTOCOL_ERROR
            );
        }

        $address = $this->client->getLocalAddress();

        $host = $matches[1];
        $port = isset($matches[2])
            ? (int) $matches[2]
            : ($address instanceof InternetAddress ? $address->getPort() : null);

        if ($position = \strpos($target, "#")) {
            $target = \substr($target, 0, $position);
        }

        if ($position = \strpos($target, "?")) {
            $query = \substr($target, $position + 1);
            $target = \substr($target, 0, $position);
        }

        try {
            if ($target === "*") {
                $uri = Uri\Http::fromComponents([
                    "scheme" => $scheme,
                    "host" => $host,
                    "port" => $port,
                ]);
            } else {
                $uri = Uri\Http::fromComponents([
                    "scheme" => $scheme,
                    "host" => $host,
                    "port" => $port,
                    "path" => $target,
                    "query" => $query,
                ]);
            }
        } catch (Uri\Contracts\UriException $exception) {
            throw new Http2ConnectionException(
                "Invalid request URI",
                Http2Parser::PROTOCOL_ERROR
            );
        }

        $this->pinged = 0; // Reset ping count when a request is received.

        if ($streamEnded) {
            $request = new Request(
                $this->client,
                $method,
                $uri,
                $headers,
                "",
                "2"
            );

            $this->timeoutTracker->suspend($streamId);

            $this->streamIdMap[$request] = $streamId;
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

        if ($this->serverWindow <= self::MINIMUM_WINDOW) {
            $this->serverWindow += self::MAX_INCREMENT;
            $this->writeFrame(\pack("N", self::MAX_INCREMENT), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG);
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
                "Invalid headers field in trailers",
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

        $this->streamIdMap[$request] = $streamId;
        $stream->pendingResponse = async($this->handleRequest(...), $request);
    }

    public function handleData(int $streamId, string $data): void
    {
        $length = \strlen($data);

        if ($streamId & 1) {
            $this->timeoutTracker->update($streamId);
        }

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
            $this->createStream($streamId, $this->bodySizeLimit);
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

                    $this->allowsPush = ((bool) $value) && $this->pushEnabled;
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
            new ClientException($this->client, "HTTP/2 connection error", $exception->getCode(), $exception)
        );
    }

    public function getApplicationLayerProtocols(): array
    {
        return ['h2'];
    }
}
