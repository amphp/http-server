<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Quic\QuicConnection;
use Amp\Socket\Socket;

interface HttpDriver
{
    public const DEFAULT_CONNECTION_TIMEOUT = 60;
    public const DEFAULT_STREAM_TIMEOUT = 15;
    public const DEFAULT_HEADER_SIZE_LIMIT = 32768;
    public const DEFAULT_BODY_SIZE_LIMIT = 131072;

    /**
     * @return string[] A list of supported application-layer protocols (ALPNs).
     */
    public function getApplicationLayerProtocols(): array;

    /**
     * Set up the driver in a socket independent way.
     */
    public function handleClient(
        Client $client,
        ReadableStream $readableStream,
        WritableStream $writableStream,
    ): void;

    /**
     * Set up the driver directly through a connection.
     */
    public function handleConnection(
        Client $client,
        Socket|QuicConnection $connection
    ): void;

    /**
     * @return int Number of requests that are being read by the parser.
     */
    public function getPendingRequestCount(): int;

    /**
     * Stops processing further requests, returning once all currently pending requests have been fulfilled and any
     * remaining data is sent to the client (such as GOAWAY frames for HTTP/2).
     */
    public function stop(): void;
}
