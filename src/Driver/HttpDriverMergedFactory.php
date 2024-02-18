<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\RequestHandler;

class HttpDriverMergedFactory implements HttpDriverFactory
{
    private int $index = 0;

    /**
     * @param list<HttpDriverMiddleware> $middlewares
     */
    public function __construct(private array $middlewares, private HttpDriverFactory $factory)
    {
    }

    public function createHttpDriver(RequestHandler $requestHandler, ErrorHandler $errorHandler, Client $client): HttpDriver
    {
        if ($this->index >= \count($this->middlewares)) {
            return $this->factory->createHttpDriver($requestHandler, $errorHandler, $client);
        }

        $factory = clone $this;
        ++$factory->index;
        return $this->middlewares[$this->index]->createHttpDriver($factory, $requestHandler, $errorHandler, $client);
    }

    public function getApplicationLayerProtocols(): array
    {
        return $this->factory->getApplicationLayerProtocols();
    }
}
