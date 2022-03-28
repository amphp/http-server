<?php

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;

interface HttpDriver
{
    /**
     * @return string[] A list of supported application-layer protocols (ALPNs).
     */
    public function getApplicationLayerProtocols(): array;

    /**
     * Set up the driver.
     */
    public function handleClient(
        Client $client,
        ReadableStream $readableStream,
        WritableStream $writableStream,
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
