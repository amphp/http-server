<?php

namespace Aerys;

class WorkerProcess extends Process {
    private $logger;
    private $ipcSock;
    private $bootstrapper;
    private $server;

    public function __construct(Logger $logger, $ipcSock, Bootstrapper $bootstrapper = null) {
        parent::__construct($logger);
        $this->logger = $logger;
        $this->ipcSock = $ipcSock;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
    }

    protected function doStart(Console $console): \Generator {
        $server = yield from $this->bootstrapper->boot($this->logger, $console);
        yield $server->start();
        $this->server = $server;
        \Amp\onReadable($this->ipcSock, function($watcherId) {
            \Amp\cancel($watcherId);
            yield from $this->stop();
        });
    }

    protected function doStop(): \Generator {
        if ($this->server) {
            yield $this->server->stop();
        }
        $this->logger->flush();
    }

    protected function exit() {
        $this->logger->flush();
        parent::exit();
    }
}
