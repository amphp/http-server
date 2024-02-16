<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\Pipe;
use Amp\ByteStream\ReadableIterableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Internal\ConnectionHttpDriver;
use Amp\Http\Server\Driver\Internal\Http3\Http3ConnectionException;
use Amp\Http\Server\Driver\Internal\Http3\Http3DatagramStream;
use Amp\Http\Server\Driver\Internal\Http3\Http3Error;
use Amp\Http\Server\Driver\Internal\Http3\Http3Frame;
use Amp\Http\Server\Driver\Internal\Http3\Http3Parser;
use Amp\Http\Server\Driver\Internal\Http3\Http3Settings;
use Amp\Http\Server\Driver\Internal\Http3\Http3StreamException;
use Amp\Http\Server\Driver\Internal\Http3\Http3Writer;
use Amp\Http\Server\Driver\Internal\Http3\QPack;
use Amp\Http\Server\Driver\Internal\UnbufferedBodyStream;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\Pipeline\Queue;
use Amp\Quic\QuicConnection;
use Amp\Quic\QuicSocket;
use Amp\Socket\InternetAddress;
use Amp\Socket\Socket;
use League\Uri;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Http\formatDateHeader;

/**
 * @psalm-import-type HeaderArray from \Amp\Http\Server\Driver\Internal\Http3\QPack
 */
class Http3Driver extends ConnectionHttpDriver
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private Client $client;

    /** @var \WeakMap<Request, QuicSocket> */
    private \WeakMap $requestStreams;

    /** @psalm-suppress PropertyNotSetInConstructor */
    private Http3Parser $parser;
    /** @psalm-suppress PropertyNotSetInConstructor */
    private Http3Writer $writer;
    private int $highestStreamId = 0;
    private bool $stopping = false;
    private DeferredCancellation $closeCancellation;
    /** @var array<int, int> */
    private array $settings = [];
    /** @var DeferredFuture<array<int, int>> */
    private DeferredFuture $parsedSettings;
    /** @var array<int, \Closure(string $buf, QuicSocket $stream): void> */
    private array $bidirectionalStreamHandlers = [];
    /** @var array<int, \Closure(string $buf, QuicSocket $stream): void> */
    private array $unidirectionalStreamHandlers = [];

    /**
     * @param positive-int $headerSizeLimit
     * @param non-negative-int $bodySizeLimit
     */
    public function __construct(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        private readonly int $streamTimeout = HttpDriver::DEFAULT_STREAM_TIMEOUT,
        private readonly int $headerSizeLimit = HttpDriver::DEFAULT_HEADER_SIZE_LIMIT,
        private readonly int $bodySizeLimit = HttpDriver::DEFAULT_BODY_SIZE_LIMIT,
    ) {
        parent::__construct($requestHandler, $errorHandler, $logger);

        /** @var \WeakMap<Request, QuicSocket> See https://github.com/vimeo/psalm/issues/7131 */
        $this->requestStreams = new \WeakMap;
        $this->closeCancellation = new DeferredCancellation;
        $this->settings[Http3Settings::MAX_FIELD_SECTION_SIZE->value] = $this->headerSizeLimit;
        $this->settings[Http3Settings::ENABLE_CONNECT_PROTOCOL->value] = 1;
        $this->parsedSettings = new DeferredFuture;
    }

    /** @return array<int, int> */
    public function getSettings(): \array
    {
        return $this->parsedSettings->getFuture()->await();
    }

    public function addSetting(Http3Settings|int $setting, int $value): void
    {
        $this->settings[\is_int($setting) ? $setting : $setting->value] = $value;
    }

    /** @param \Closure(string $buf, QuicSocket $stream): void $handler */
    public function addUnidirectionalStreamHandler(int $type, \Closure $handler): void
    {
        $this->bidirectionalStreamHandlers[$type] = $handler;
    }

    /** @param \Closure(string $buf, QuicSocket $stream): void $handler */
    public function addBidirectionalStreamHandler(int $type, \Closure $handler): void
    {
        $this->unidirectionalStreamHandlers[$type] = $handler;
    }

    /**
     * @param array<string, scalar|list<scalar>> $headers
     * @return HeaderArray
     * @psalm-suppress LessSpecificReturnStatement, MoreSpecificReturnType
     */
    private function encodeHeaders(array $headers): array
    {
        foreach ($headers as $field => $values) {
            if (\is_array($values)) {
                foreach ($values as $k => $value) {
                    if (!\is_string($value)) {
                        /** @psalm-suppress PossiblyInvalidArrayAssignment */
                        $headers[$field][$k] = (string) $value;
                    }
                }
            } else {
                $headers[$field] = [(string) $values];
            }
        }

        return $headers;
    }

    protected function write(Request $request, Response $response): void
    {
        /** @var QuicSocket $stream */
        $stream = $this->requestStreams[$request];

        $status = $response->getStatus();
        $headers = [
            ':status' => [(string) $status],
            ...$response->getHeaders(),
            'date' => [formatDateHeader()],
        ];

        // Remove headers that are obsolete in HTTP/3.
        unset($headers["connection"], $headers["keep-alive"], $headers["transfer-encoding"]);

        $trailers = $response->getTrailers();

        if ($trailers !== null && !isset($headers["trailer"]) && ($fields = $trailers->getFields())) {
            $headers["trailer"] = [\implode(", ", $fields)];
        }

        foreach ($response->getPushes() as $push) {
            $headers["link"][] = "<{$push->getUri()}>; rel=preload";
        }

        $this->writer->sendHeaderFrame($stream, $this->encodeHeaders($headers));

        if ($request->getMethod() === "HEAD") {
            return;
        }

        if ($response->isUpgraded() && $request->getMethod() === "CONNECT") {
            $status = $response->getStatus();
            if ($status >= 200 && $status <= 299) {
                $this->upgrade($stream, $request, $response);
            }
        }

        try {
            $cancellation = $this->closeCancellation->getCancellation();

            $body = $response->getBody();
            $chunk = $body->read($cancellation);

            while ($chunk !== null) {
                $this->writer->sendData($stream, $chunk);

                $chunk = $body->read($cancellation);
            }

            if ($trailers !== null) {
                $trailers = $trailers->await($cancellation);
                $this->writer->sendHeaderFrame($stream, $this->encodeHeaders($trailers->getHeaders()));
            }

            $stream->end();
            if (!$stream->isClosed()) {
                $stream->endReceiving();
            }
        } catch (CancelledException) {
        }

        unset($this->requestStreams[$request]);
    }

    /**
     * Invokes the upgrade handler of the Response with the socket upgraded from the HTTP server.
     */
    private function upgrade(QuicSocket $stream, Request $request, Response $response): void
    {
        $upgradeHandler = $response->getUpgradeHandler();
        if (!$upgradeHandler) {
            throw new \Error('Response was not upgraded');
        }

        // The input RequestBody are parsed raw DATA frames - exactly what we need (see CONNECT)
        $inputStream = new UnbufferedBodyStream($request->getBody());
        $request->setBody(""); // hide the body from the upgrade handler, it's available in the UpgradedSocket

        // The output of an upgraded connection is just DATA frames
        $outputPipe = new Pipe(0);

        $settings = $this->parsedSettings->getFuture()->await();
        $datagramStream = empty($settings[Http3Settings::H3_DATAGRAM->value]) ? null : new Http3DatagramStream($this->parser->receiveDatagram(...), $this->writer->writeDatagram(...), $this->writer->maxDatagramSize(...), $stream);

        $upgraded = new UpgradedSocket(new SocketClient($stream, $stream->getId()), $inputStream, $outputPipe->getSink(), $datagramStream);

        try {
            $upgradeHandler($upgraded, $request, $response);
        } catch (\Throwable $exception) {
            $exceptionClass = $exception::class;

            $this->logger->error(
                "Unexpected {$exceptionClass} thrown during socket upgrade, closing stream.",
                ['exception' => $exception]
            );

            $stream->resetSending(Http3Error::H3_INTERNAL_ERROR->value);
        }

        $response->removeTrailers();
        $response->setBody($outputPipe->getSource());

        self::getTimeoutQueue()->remove($this->client, $stream->getId());
    }

    public function getApplicationLayerProtocols(): array
    {
        return ["h3"]; // that's a property of the server itself...? "h3" is the default mandated by RFC 9114, but section 3.1 allows for custom mechanisms too, technically.
    }

    public function handleConnection(Client $client, QuicConnection|Socket $connection): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(!isset($this->client), "The driver has already been setup");
        \assert($connection instanceof QuicConnection);

        $qpack = new QPack;

        $this->client = $client;
        $this->writer = new Http3Writer($connection, $this->settings, $qpack);
        $largestPushId = (1 << 62) - 1;
        $maxAllowedPushId = 0;

        $connection->onClose($this->closeCancellation->cancel(...));

        $this->parser = $parser = new Http3Parser($connection, $this->headerSizeLimit, $qpack);
        try {
            foreach ($parser->process() as $frame) {
                $type = $frame[0];
                switch ($type) {
                    case Http3Frame::SETTINGS:
                        [, $settings] = $frame;
                        $this->parsedSettings->complete($settings);
                        break;

                    case Http3Frame::HEADERS:
                        /** @var QuicSocket $stream */
                        [, $stream, $generator] = $frame;
                        if ($this->stopping) {
                            [, $stream] = $frame;
                            $stream->close(Http3Error::H3_NO_ERROR->value);
                            break;
                        }
                        EventLoop::queue(function () use ($parser, $stream, $generator) {
                            $streamId = $stream->getId();

                            try {
                                [$headers, $pseudo] = $generator->current();
                                if (!isset($pseudo[":method"], $pseudo[":authority"])
                                    || isset($headers["connection"])
                                    || (isset($headers["te"]) && \implode($headers["te"]) !== "trailers")
                                ) {
                                    throw new Http3StreamException(
                                        "Invalid header values",
                                        $stream,
                                        Http3Error::H3_MESSAGE_ERROR
                                    );
                                }

                                // Per RFC 9220 Section 3 & RFC 8441 Section 4, Extended CONNECT (recognized by the existence of :protocol) must include :path and :scheme,
                                // but normal CONNECT must not according to RFC 9114 Section 4.4.
                                if ($pseudo[":method"] === "CONNECT" && !isset($pseudo[":protocol"]) && !isset($pseudo[":path"]) && !isset($pseudo[":scheme"])) {
                                    $pseudo[":path"] = "";
                                    $pseudo[":scheme"] = null;
                                } elseif (!isset($pseudo[":path"], $pseudo[":scheme"]) || $pseudo[":path"] === '') {
                                    throw new Http3StreamException(
                                        "Invalid header values",
                                        $stream,
                                        Http3Error::H3_MESSAGE_ERROR
                                    );
                                }

                                foreach ($pseudo as $name => $value) {
                                    if (!isset(Http2Parser::KNOWN_REQUEST_PSEUDO_HEADERS[$name])) {
                                        if ($name === ":protocol") {
                                            if ($pseudo[":method"] !== "CONNECT") {
                                                throw new Http3StreamException(
                                                    "The :protocol pseudo header is only allowed for CONNECT methods",
                                                    $stream,
                                                    Http3Error::H3_MESSAGE_ERROR
                                                );
                                            }
                                        } else {
                                            throw new Http3StreamException(
                                                "Invalid pseudo header",
                                                $stream,
                                                Http3Error::H3_MESSAGE_ERROR
                                            );
                                        }
                                    }
                                }

                                [':method' => $method, ':path' => $target, ':scheme' => $scheme, ':authority' => $host] = $pseudo;
                                $query = null;

                                if (!\preg_match("#^([A-Z\d.\-]+|\[[\d:]+])(?::([1-9]\d*))?$#i", $host, $matches)) {
                                    throw new Http3StreamException(
                                        "Invalid authority (host) name",
                                        $stream,
                                        Http3Error::H3_MESSAGE_ERROR
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
                                        /** @psalm-suppress DeprecatedMethod */
                                        $uri = Uri\Http::createFromComponents([
                                            "scheme" => $scheme,
                                            "host" => $host,
                                            "port" => $port,
                                        ]);
                                    } else {
                                        /** @psalm-suppress DeprecatedMethod */
                                        $uri = Uri\Http::createFromComponents([
                                            "scheme" => $scheme,
                                            "host" => $host,
                                            "port" => $port,
                                            "path" => $target,
                                            "query" => $query,
                                        ]);
                                    }
                                } catch (Uri\Contracts\UriException $exception) {
                                    throw new Http3StreamException(
                                        "Invalid request URI",
                                        $stream,
                                        Http3Error::H3_MESSAGE_ERROR,
                                        $exception
                                    );
                                }

                                $trailerDeferred = new DeferredFuture;
                                $bodyQueue = new Queue;

                                try {
                                    $trailers = new Trailers(
                                        $trailerDeferred->getFuture(),
                                        isset($headers['trailers'])
                                            ? \array_map('trim', \explode(',', \implode(',', $headers['trailers'])))
                                            : []
                                    );
                                } catch (InvalidHeaderException $exception) {
                                    throw new Http3StreamException(
                                        "Invalid headers field in trailers",
                                        $stream,
                                        Http3Error::H3_MESSAGE_ERROR,
                                        $exception
                                    );
                                }

                                if (isset($headers["content-length"])) {
                                    if (isset($headers["content-length"][1])) {
                                        throw new Http3StreamException(
                                            "Received multiple content-length headers",
                                            $stream,
                                            Http3Error::H3_MESSAGE_ERROR
                                        );
                                    }

                                    $contentLength = $headers["content-length"][0];
                                    if (!\preg_match('/^0|[1-9]\d*$/', $contentLength)) {
                                        throw new Http3StreamException(
                                            "Invalid content-length header value",
                                            $stream,
                                            Http3Error::H3_MESSAGE_ERROR
                                        );
                                    }

                                    $expectedLength = (int) $contentLength;
                                } else {
                                    $expectedLength = null;
                                }

                                if (isset($headers["priority"])) {
                                    $this->updatePriority($stream, $headers["priority"]);
                                }

                                /** @var EventLoop\Suspension|null $dataSuspension */
                                $dataSuspension = null;
                                $bodySizeLimit = $this->bodySizeLimit;
                                $body = new RequestBody(
                                    new ReadableIterableStream($bodyQueue->pipe()),
                                    function (int $bodySize) use (&$bodySizeLimit, &$dataSuspension) {
                                        if ($bodySizeLimit >= $bodySize) {
                                            return;
                                        }

                                        $bodySizeLimit = $bodySize;

                                        $dataSuspension?->resume();
                                        $dataSuspension = null;
                                    }
                                );

                                $request = new Request(
                                    $this->client,
                                    $method,
                                    $uri,
                                    $headers,
                                    $body,
                                    "3",
                                    $trailers,
                                    $pseudo[":protocol"] ?? "",
                                );
                                $this->requestStreams[$request] = $stream;

                                if ($this->highestStreamId < $streamId) {
                                    $this->highestStreamId = $streamId;
                                }

                                async($this->handleRequest(...), $request);

                                self::getTimeoutQueue()->insert($this->client, $streamId, fn () => $stream->close(Http3Error::H3_REQUEST_CANCELLED->value), $this->streamTimeout);

                                $currentBodySize = 0;
                                for ($generator->next(); $generator->valid(); $generator->next()) {
                                    $type = $generator->key();
                                    $data = $generator->current();
                                    if ($type === Http3Frame::DATA) {
                                        self::getTimeoutQueue()->update($this->client, $streamId, $this->streamTimeout);

                                        $len = \strlen($data);
                                        if ($expectedLength !== null) {
                                            $expectedLength -= $len;
                                            if ($expectedLength < 0) {
                                                throw new Http3StreamException(
                                                    "Body length does not match content-length header",
                                                    $stream,
                                                    Http3Error::H3_MESSAGE_ERROR
                                                );
                                            }
                                        }
                                        $currentBodySize += $len;
                                        $bodyQueue->push($data);
                                        while ($currentBodySize > $bodySizeLimit) {
                                            $dataSuspension = EventLoop::getSuspension();
                                            $dataSuspension->suspend();
                                        }
                                    } elseif ($type === Http3Frame::HEADERS) {
                                        [$headers, $pseudo] = $data;

                                        // Trailers must not contain pseudo-headers.
                                        if (!empty($pseudo)) {
                                            throw new Http3StreamException(
                                                "Trailers must not contain pseudo headers",
                                                $stream,
                                                Http3Error::H3_MESSAGE_ERROR
                                            );
                                        }

                                        // Trailers must not contain any disallowed fields.
                                        if (\array_intersect_key($headers, Trailers::DISALLOWED_TRAILERS)) {
                                            throw new Http3StreamException(
                                                "Disallowed trailer field name",
                                                $stream,
                                                Http3Error::H3_MESSAGE_ERROR
                                            );
                                        }

                                        $trailerDeferred->complete($headers);
                                        $trailerDeferred = null;
                                        break;
                                    } elseif ($type === Http3Frame::PUSH_PROMISE) {
                                        throw new Http3ConnectionException("A PUSH_PROMISE may not be sent on the request stream", Http3Error::H3_FRAME_UNEXPECTED);
                                    } else {
                                        // Stream reset
                                        $ex = new ClientException($this->client, "Client aborted the request", Http3Error::H3_REQUEST_REJECTED->value);
                                        $bodyQueue->error($ex);
                                        $trailerDeferred->error($ex);
                                        return;
                                    }
                                }
                                if ($expectedLength) {
                                    throw new Http3StreamException(
                                        "Body length does not match content-length header",
                                        $stream,
                                        Http3Error::H3_MESSAGE_ERROR
                                    );
                                }
                                $bodyQueue->complete();
                                $trailerDeferred?->complete([]);
                            } catch (\Throwable $e) {
                                if (isset($bodyQueue)) {
                                    $bodyQueue->error($e);
                                }
                                if (isset($trailerDeferred)) {
                                    $trailerDeferred->error($e);
                                }
                                if ($e instanceof Http3ConnectionException) {
                                    $parser->abort($e);
                                } elseif ($e instanceof Http3StreamException) {
                                    $stream->resetSending($e->getCode());
                                } else {
                                    $stream->resetSending(Http3Error::H3_INTERNAL_ERROR->value);
                                    throw $e; // rethrow it right into the event loop
                                }
                            } finally {
                                self::getTimeoutQueue()->remove($this->client, $streamId);
                            }
                        });
                        break;

                    case Http3Frame::GOAWAY:
                        [, $maxPushId] = $frame;
                        if ($maxPushId > $largestPushId) {
                            $parser->abort(new Http3ConnectionException("A GOAWAY id must not be larger than a prior one", Http3Error::H3_ID_ERROR));
                            break;
                        }
                        // Nothing to do here, we don't support pushes.
                        break;

                    case Http3Frame::MAX_PUSH_ID:
                        [, $maxPushId] = $frame;
                        if ($maxPushId < $maxAllowedPushId) {
                            $parser->abort(new Http3ConnectionException("A MAX_PUSH_ID id must not be smaller than a prior one", Http3Error::H3_ID_ERROR));
                            break;
                        }
                        $maxAllowedPushId = $maxPushId;
                        break;

                    case Http3Frame::CANCEL_PUSH:
                        [, $pushId] = $frame;
                        // Without pushes sent, this frame is always invalid
                        $parser->abort(new Http3ConnectionException("An CANCEL_PUSH for a not promised $pushId was received", Http3Error::H3_ID_ERROR));
                        break;

                    case Http3Frame::PUSH_PROMISE:
                        $parser->abort(new Http3ConnectionException("A push stream must not be initiated by the client", Http3Error::H3_STREAM_CREATION_ERROR));
                        break;

                    case Http3Frame::PRIORITY_UPDATE_Request:
                        [, $streamId, $structuredUpdate] = $frame;
                        // The RFC says we _should_ temporarily buffer unknown stream ids. We currently don't for simplicity. To eventually improve?
                        if ($stream = $connection->getStream($streamId)) {
                            $this->updatePriority($stream, $structuredUpdate);
                        }
                        break;

                    case Http3Frame::PRIORITY_UPDATE_Push:
                        $parser->abort(new Http3ConnectionException("No PRIORITY_UPDATE frame may be sent for unpromised push streams", Http3Error::H3_ID_ERROR));
                        break;

                    default:
                        [, $buf, $stream] = $frame;
                        $handlers = ($stream->getId() & 0x2) ? $this->unidirectionalStreamHandlers : $this->bidirectionalStreamHandlers;
                        if (isset($handlers[$type])) {
                            $handlers[$type]($buf, $stream);
                            break;
                        }
                        $parser->abort(new Http3ConnectionException("An unexpected stream or frame was received", Http3Error::H3_FRAME_UNEXPECTED));
                }
            }
        } catch (Http3ConnectionException $e) {
            $this->logger->notice("HTTP/3 connection error for client {address}: {message}", [
                'address' => $this->client->getRemoteAddress()->toString(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function updatePriority(QuicSocket $socket, array|string $headers): void
    {
        if ([$urgency, $incremental] = Http3Parser::parsePriority($headers)) {
            /** @psalm-suppress PossiblyNullArgument https://github.com/vimeo/psalm/issues/10668 */
            $socket->setPriority($urgency + 124 /* 127 is default for QUIC, 3 is default for HTTP */, $incremental);
        }
    }

    public function getPendingRequestCount(): int
    {
        return $this->requestStreams->count();
    }

    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!isset($this->writer)) {
            return;
        }

        $this->writer->sendGoaway($this->highestStreamId);

        /** @psalm-suppress RedundantCondition */
        \assert($this->logger->debug(\sprintf(
            "Gracefully shutting down HTTP/3 client @ %s #%d; last-id: %d",
            $this->client->getRemoteAddress()->toString(),
            $this->client->getId(),
            $this->highestStreamId,
        )) || true);

        $outstanding = $this->requestStreams->count();
        if ($outstanding === 0) {
            \Amp\delay(1); // TODO: arbitrary timeout?
            $this->writer->close();
            return;
        }

        $deferred = new DeferredFuture;
        foreach ($this->requestStreams as $stream) {
            $stream->onClose(function () use (&$outstanding, $deferred) {
                if (--$outstanding === 0) {
                    $deferred->complete();
                }
            });
        }

        try {
            $deferred->getFuture()->await($this->closeCancellation->getCancellation());
        } catch (CancelledException) {
        } finally {
            \Amp\delay(1); // TODO: arbitrary timeout? With QUIC a connection close is effectively resetting all streams, so it may also reset a successful response
            $this->writer->close();
        }
    }
}
