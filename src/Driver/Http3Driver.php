<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableIterableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Internal\ConnectionHttpDriver;
use Amp\Http\Server\Driver\Internal\Http3\Http3ConnectionException;
use Amp\Http\Server\Driver\Internal\Http3\Http3Error;
use Amp\Http\Server\Driver\Internal\Http3\Http3Frame;
use Amp\Http\Server\Driver\Internal\Http3\Http3Parser;
use Amp\Http\Server\Driver\Internal\Http3\Http3Settings;
use Amp\Http\Server\Driver\Internal\Http3\Http3StreamException;
use Amp\Http\Server\Driver\Internal\Http3\Http3Writer;
use Amp\Http\Server\Driver\Internal\Http3\QPack;
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

class Http3Driver extends ConnectionHttpDriver
{
    private Client $client;

    /** @var \WeakMap<Request, QuicSocket> */
    private \WeakMap $requestStreams;

    private Http3Writer $writer;
    private QPack $qpack;
    private int $highestStreamId = 0;
    private bool $stopping = false;
    private DeferredCancellation $closeCancellation;

    public function __construct(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        private readonly int $streamTimeout = Http2Driver::DEFAULT_STREAM_TIMEOUT,
        private readonly int $headerSizeLimit = Http2Driver::DEFAULT_HEADER_SIZE_LIMIT,
        private readonly int $bodySizeLimit = Http2Driver::DEFAULT_BODY_SIZE_LIMIT,
        private readonly ?string $settings = null,
    ) {
        parent::__construct($requestHandler, $errorHandler, $logger);

        $this->qpack = new QPack;
        $this->requestStreams = new \WeakMap;
        $this->closeCancellation = new DeferredCancellation;
    }

    // TODO copied from Http2Driver...
    private function encodeHeaders(array $headers): string
    {
        $input = [];

        foreach ($headers as $field => $values) {
            $values = (array) $values;

            foreach ($values as $value) {
                $input[] = [(string) $field, (string) $value];
            }
        }

        return $this->qpack->encode($input);
    }

    protected function write(Request $request, Response $response): void
    {
        /** @var QuicSocket $stream */
        $stream = $this->requestStreams[$request];

        $status = $response->getStatus();
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
        }

        $this->writer->sendHeaderFrame($stream, $this->encodeHeaders($headers));

        if ($request->getMethod() === "HEAD") {
            return;
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

    public function getApplicationLayerProtocols(): array
    {
        return ["h3"]; // that's a property of the server itself...? "h3" is the default mandated by RFC 9114, but section 3.1 allows for custom mechanisms too, technically.
    }

    public function handleConnection(Client $client, QuicConnection|Socket $connection): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        \assert(!isset($this->client), "The driver has already been setup");

        $this->client = $client;
        $this->writer = new Http3Writer($connection, [[Http3Settings::MAX_FIELD_SECTION_SIZE, $this->headerSizeLimit]]);
        $largestPushId = (1 << 62) - 1;
        $maxAllowedPushId = 0;

        $connection->onClose($this->closeCancellation->cancel(...));

        $parser = new Http3Parser($connection, $this->headerSizeLimit, $this->qpack);
        try {
            foreach ($parser->process() as $frame) {
                $type = $frame[0];
                switch ($type) {
                    case Http3Frame::SETTINGS:
                        // something to do?
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
                            try {
                                $streamId = $stream->getId();

                                [$headers, $pseudo] = $generator->current();
                                foreach ($pseudo as $name => $value) {
                                    if (!isset(Http2Parser::KNOWN_REQUEST_PSEUDO_HEADERS[$name])) {
                                        throw new Http3StreamException(
                                            "Invalid pseudo header",
                                            $stream,
                                            Http3Error::H3_MESSAGE_ERROR
                                        );
                                    }
                                }

                                if (!isset($pseudo[":method"], $pseudo[":path"], $pseudo[":scheme"], $pseudo[":authority"])
                                    || isset($headers["connection"])
                                    || $pseudo[":path"] === ''
                                    || (isset($headers["te"]) && \implode($headers["te"]) !== "trailers")
                                ) {
                                    throw new Http3StreamException(
                                        "Invalid header values",
                                        $stream,
                                        Http3Error::H3_MESSAGE_ERROR
                                    );
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
                                $bodyQueue = new Queue();

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

                                $dataSuspension = null;
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
                                    $trailers
                                );
                                $this->requestStreams[$request] = $stream;

                                if ($this->highestStreamId < $streamId) {
                                    $this->highestStreamId = $streamId;
                                }

                                async($this->handleRequest(...), $request);

                                $generator->next();
                                $currentBodySize = 0;
                                if ($generator->valid()) {
                                    foreach ($generator as $type => $data) {
                                        if ($type === Http3Frame::DATA) {
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
                                }
                                if ($expectedLength) {
                                    throw new Http3StreamException(
                                        "Body length does not match content-length header",
                                        $stream,
                                        Http3Error::H3_MESSAGE_ERROR
                                    );
                                }
                                $bodyQueue->complete();
                                $trailerDeferred?->complete();
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

                    default:
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
            $this->writer->close();
        }
    }
}
