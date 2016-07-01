<?php

namespace Aerys;

use Psr\Log\LoggerInterface as PsrLogger;

class DebugProcess extends Process {
    private $logger;
    private $bootstrapper;
    private $server;

    public function __construct(PsrLogger $logger, Bootstrapper $bootstrapper = null) {
        parent::__construct($logger);
        $this->logger = $logger;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
    }

    protected function doStart(Console $console): \Generator {
        if ($console->isArgDefined("restart")) {
            $this->logger->critical("You cannot restart a debug aerys instance via command");
            exit(1);
        }

        if (ini_get("zend.assertions") === "-1") {
            $this->logger->warning(
                "Running aerys in debug mode with assertions disabled is not recommended; " .
                "enable assertions in php.ini (zend.assertions = 1) " .
                "or disable debug mode (-d) to hide this warning."
            );
        } else {
            ini_set("zend.assertions", 1);
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
