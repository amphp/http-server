<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\SocketServerFactory;

interface HttpDriverFactory extends SocketServerFactory
{
    public function createHttpDriver(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        Client $client,
    ): HttpDriver;
}
