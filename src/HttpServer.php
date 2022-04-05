<?php

namespace Amp\Http\Server;

use Amp\Socket\SocketServer;

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

    /**
     * @return list<SocketServer>
     */
    public function getServers(): array;

    public function getStatus(): HttpServerStatus;
}
