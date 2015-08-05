<?php

namespace Aerys;

use Amp\Reactor;

class WorkerProcess extends Process {
    private $reactor;
    private $logger;
    private $ipcSock;
    private $bootstrapper;
    private $server;

    public function __construct(Reactor $reactor, Logger $logger, $ipcSock, Bootstrapper $bootstrapper = null) {
        parent::__construct($reactor, $logger);
        $this->reactor = $reactor;
        $this->logger = $logger;
        $this->ipcSock = $ipcSock;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
    }

    protected function doStart(Console $console): \Generator {
        $server = $this->bootstrapper->boot($this->reactor, $this->logger, $console);
        yield $server->start();
        $this->server = $server;
        $this->reactor->onReadable($this->ipcSock, function($watcherId) {
            $this->reactor->cancel($watcherId);
            yield from $this->stop();
        });
    }

    protected function doStop(): \Generator {
        if ($this->server) {
            yield $this->server->stop();
            yield $this->logger->stop();
        }
    }
}
