<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\RequestHandler;

interface HttpDriverMiddleware
{
    public function createHttpDriver(
        HttpDriverFactory $factory,
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        Client $client,
    ): HttpDriver;

    /**
     * @return list<string>
     */
    public function getApplicationLayerProtocols(HttpDriverMiddleware $next): array;
}
