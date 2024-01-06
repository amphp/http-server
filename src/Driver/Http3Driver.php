<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\Driver\Internal\ConnectionHttpDriver;
use Amp\Http\Server\Driver\Internal\QPack;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Quic\QuicConnection;
use Amp\Socket\Socket;
use Psr\Log\LoggerInterface as PsrLogger;

class Http3Driver extends ConnectionHttpDriver
{
    private bool $allowsPush;

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
    }

    protected function write(Request $request, Response $response): void
    {

    }

    public function getApplicationLayerProtocols(): array
    {
        return ["h3"]; // that's a property of the server itself...? "h3" is the default mandated by RFC 9114, but section 3.1 allows for custom mechanisms too, technically.
    }

    public function handleConnection(Client $client, QuicConnection|Socket $connection): void
    {

    }

    public function getPendingRequestCount(): int
    {
        return 0;
    }

    public function stop(): void
    {

    }
}
