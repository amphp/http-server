<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableIterableStream;
use Amp\DeferredFuture;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\Driver\Internal\ConnectionHttpDriver;
use Amp\Http\Server\Driver\Internal\Http2Stream;
use Amp\Http\Server\Driver\Internal\Http3\Http3Frame;
use Amp\Http\Server\Driver\Internal\Http3\Http3Parser;
use Amp\Http\Server\Driver\Internal\Http3\Http3Settings;
use Amp\Http\Server\Driver\Internal\Http3\Http3Writer;
use Amp\Http\Server\Driver\Internal\Http3\QPack;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestBody;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Trailers;
use Amp\NullCancellation;
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
    private bool $allowsPush;

    private Client $client;
    private QuicConnection $connection;

    /** @var \WeakMap<Request, QuicSocket> */
    private \WeakMap $requestStreams;

    private Http3Writer $writer;
    private QPack $qpack;

    public function __construct(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        private readonly int $streamTimeout = Http2Driver::DEFAULT_STREAM_TIMEOUT,
        private readonly int $headerSizeLimit = Http2Driver::DEFAULT_HEADER_SIZE_LIMIT,
        private readonly int $bodySizeLimit = Http2Driver::DEFAULT_BODY_SIZE_LIMIT,
        private readonly bool $pushEnabled = true,
        private readonly ?string $settings = null,
    ) {
        parent::__construct($requestHandler, $errorHandler, $logger);

        $this->allowsPush = $pushEnabled;

        $this->qpack = new QPack;
        $this->requestStreams = new \WeakMap;
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
        unset($this->requestStreams[$request]);

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
            if ($this->allowsPush) {
                // TODO $this->sendPushPromise($request, $id, $push);
            }
        }

        $this->writer->sendHeaderFrame($stream, $this->encodeHeaders($headers));

        if ($request->getMethod() === "HEAD") {
            return;
        }

        $cancellation = new NullCancellation; // TODO just dummy

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
        $this->connection = $connection;
        $this->writer = new Http3Writer($connection, [[Http3Settings::MAX_FIELD_SECTION_SIZE, $this->headerSizeLimit]]);

        $parser = new Http3Parser($connection, $this->headerSizeLimit, $this->qpack);
        foreach ($parser->process() as $frame) {
            $type = $frame[0];
            switch ($type) {
                case Http3Frame::SETTINGS:
                    // something to do?
                    break;

                case Http3Frame::HEADERS:
                    EventLoop::queue(function () use ($frame) {
                        /** @var QuicSocket $stream */
                        $stream = $frame[1];
                        $generator = $frame[2];

                        [$headers, $pseudo] = $generator->current();
                        foreach ($pseudo as $name => $value) {
                            if (!isset(Http2Parser::KNOWN_REQUEST_PSEUDO_HEADERS[$name])) {
                                return;
                            }
                        }

                        if (!isset($pseudo[":method"], $pseudo[":path"], $pseudo[":scheme"], $pseudo[":authority"])
                            || isset($headers["connection"])
                            || $pseudo[":path"] === ''
                            || (isset($headers["te"]) && \implode($headers["te"]) !== "trailers")
                        ) {
                            return; // "Invalid header values"
                        }

                        [':method' => $method, ':path' => $target, ':scheme' => $scheme, ':authority' => $host] = $pseudo;
                        $query = null;

                        if (!\preg_match("#^([A-Z\d.\-]+|\[[\d:]+])(?::([1-9]\d*))?$#i", $host, $matches)) {
                            return; // "Invalid authority (host) name"
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
                            return; // "Invalid request URI",
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
                            return; // "Invalid headers field in trailers"
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
                        async($this->handleRequest(...), $request);

                        $generator->next();
                        $currentBodySize = 0;
                        if ($generator->valid()) {
                            foreach ($generator as $type => $data) {
                                if ($type === Http3Frame::DATA) {
                                    $bodyQueue->push($data);
                                    while ($currentBodySize > $bodySizeLimit) {
                                        $dataSuspension = EventLoop::getSuspension();
                                        $dataSuspension->suspend();
                                    }
                                } elseif ($type === Http3Frame::HEADERS) {
                                    // Trailers must not contain pseudo-headers.
                                    if (!empty($pseudo)) {
                                        return; // "Trailers must not contain pseudo headers"
                                    }

                                    // Trailers must not contain any disallowed fields.
                                    if (\array_intersect_key($headers, Trailers::DISALLOWED_TRAILERS)) {
                                        return; // "Disallowed trailer field name"
                                    }

                                    $trailerDeferred->complete($headers);
                                    $trailerDeferred = null;
                                    break;
                                } else {
                                    return; // Boo for push promise
                                }
                            }
                        }
                        $bodyQueue->complete();
                        $trailerDeferred?->complete();
                    });

                case Http3Frame::GOAWAY:
                    // TODO bye bye
                    break;

                case Http3Frame::MAX_PUSH_ID:
                    // TODO push
                    break;

                case Http3Frame::CANCEL_PUSH:
                    // TODO stop push
                    break;

                default:
                    // TODO invalid
                    return;
            }
        }
    }

    public function getPendingRequestCount(): int
    {
        return 0;
    }

    public function stop(): void
    {

    }
}
