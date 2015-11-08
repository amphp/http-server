<?php

namespace Aerys;

class DebugProcess extends Process {
    private $logger;
    private $bootstrapper;
    private $server;

    public function __construct(Logger $logger, Bootstrapper $bootstrapper = null) {
        parent::__construct($logger);
        $this->logger = $logger;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
    }

    protected function doStart(Console $console): \Generator {
        if ($console->isArgDefined("restart")) {
            $this->logger->critical("You cannot restart a debug aerys instance via command");
            exit;
        }

        $server = yield from $this->bootstrapper->boot($this->logger, $console);
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
