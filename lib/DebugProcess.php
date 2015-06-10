<?php

namespace Aerys;

use Amp\Reactor;

class DebugProcess extends Process {
    private $reactor;
    private $logger;
    private $bootstrapper;
    private $server;

    public function __construct(Reactor $reactor, Logger $logger, Bootstrapper $bootstrapper = null) {
        parent::__construct($reactor, $logger);
        $this->reactor = $reactor;
        $this->logger = $logger;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
    }

    protected function doStart(Console $console): \Generator {
        $server = $this->bootstrapper->boot($this->reactor, $this->logger, $console);
        yield $server->start();
        $this->server = $server;
    }

    protected function doStop(): \Generator {
        if ($this->server) {
            yield $this->server->stop();
            $this->server = null;
        }
    }
}
