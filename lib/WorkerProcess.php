<?php

namespace Aerys;

use Interop\Async\Loop;
use Psr\Log\LoggerInterface as PsrLogger;

class WorkerProcess extends Process {
    private $logger;
    private $ipcSock;
    private $bootstrapper;
    private $server;

    public function __construct(PsrLogger $logger, $ipcSock, Bootstrapper $bootstrapper = null) {
        parent::__construct($logger);
        $this->logger = $logger;
        $this->ipcSock = $ipcSock;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
    }

    protected function doStart(Console $console): \Generator {
        // Shutdown the whole server in case we needed to stop during startup
        register_shutdown_function(function() use ($console) {
            if (!$this->server) {
                // ensure a clean reactor for clean shutdown
                \Amp\execute(function() use ($console) {
                    yield ((new CommandClient((string) $console->getArg("config")))->stop());
                });
            }
        });

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
        if (\method_exists($this->logger, "flush")) {
            $this->logger->flush();
        }
    }

    protected function exit() {
        if (\method_exists($this->logger, "flush")) {
            $this->logger->flush();
        }
        parent::exit();
    }
}
