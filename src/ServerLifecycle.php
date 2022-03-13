<?php

namespace Amp\Http\Server;

use Psr\Log\LoggerInterface as PsrLogger;

interface ServerLifecycle
{
    /**
     * @param \Closure(ServerLifecycle):void $onStart
     */
    public function onStart(\Closure $onStart): void;

    /**
     * @param \Closure(ServerLifecycle):void $onStop
     */
    public function onStop(\Closure $onStop): void;

    public function getStatus(): HttpServerStatus;

    public function getErrorHandler(): ErrorHandler;

    public function getOptions(): Options;

    public function getLogger(): PsrLogger;
}
