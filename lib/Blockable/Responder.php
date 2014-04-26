<?php

namespace Aerys\Blockable;

use Amp\Dispatcher,
    Aerys\Server,
    Aerys\ServerObserver;

class Responder implements ServerObserver {
    private $dispatcher;

    /*
    threadPoolTaskTimeout   = 30
    threadPoolMaxQueueSize  = 256
    threadPoolTaskLimit     = 4096
    threadPoolMinWorkers    = 1
    threadPoolMaxWorkers    = 16
    */

    public function __construct(Dispatcher $dispatcher) {
        $this->dispatcher = $dispatcher;
    }

    public function __invoke($request) {
        unset($request['ASGI_ERROR'], $request['ASGI_INPUT']);
        return new ThreadResponseWriter($this->dispatcher, $request);
    }

    public function onServerUpdate(Server $server, $event) {
        switch ($event) {
            case Server::STARTING:
                // @TODO Assign necessary onWorkerStart tasks (like registering autoloaders
                // and shared thread state containers) with the Amp\Dispatcher.
                break;
        }
    }
}
