<?php

namespace Amp\Http\Server;

use Psr\Log\LoggerInterface as PsrLogger;

interface HttpServer
{
    /**
     * @param \Closure(HttpServer):void $onStart
     */
    public function onStart(\Closure $onStart): void;

    /**
     * @param \Closure(HttpServer):void $onStop
     */
    public function onStop(\Closure $onStop): void;

    public function getStatus(): HttpServerStatus;

    public function getErrorHandler(): ErrorHandler;

    public function getOptions(): Options;

    public function getLogger(): PsrLogger;
}
