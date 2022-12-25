<?php declare(strict_types=1);

namespace Amp\Http\Server;

interface HttpServer
{
    public function start(RequestHandler $requestHandler, ErrorHandler $errorHandler): void;

    public function stop(): void;

    /**
     * @param \Closure(HttpServer):void $onStart
     */
    public function onStart(\Closure $onStart): void;

    /**
     * @param \Closure(HttpServer):void $onStop
     */
    public function onStop(\Closure $onStop): void;

    public function getStatus(): HttpServerStatus;
}
