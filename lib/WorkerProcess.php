<?php

namespace Aerys;

use Amp\Loop;
use Psr\Log\LoggerInterface as PsrLogger;

class WorkerProcess extends Process {
    use \Amp\CallableMaker;

    private $logger;
    private $ipcSock;
    private $server;

    // Loggers which hold a watcher on $ipcSock MUST implement disableSending():Promise and enableSending() methods in order to avoid conflicts from different watchers
    public function __construct(PsrLogger $logger, $ipcSock) {
        parent::__construct($logger);
        $this->logger = $logger;
        $this->ipcSock = $ipcSock;
    }

    protected function doStart(Console $console): \Generator {
        // Shutdown the whole server in case we needed to stop during startup
        register_shutdown_function(function () use ($console) {
            if (!$this->server) {
                // ensure a clean reactor for clean shutdown
                Loop::run(function () use ($console) {
                    yield (new CommandClient((string) $console->getArg("config")))->stop();
                });
            }
        });

        $server = yield from Internal\bootServer($this->logger, $console);
        if ($console->isArgDefined("socket-transfer")) {
            \assert(\extension_loaded("sockets") && PHP_VERSION_ID > 70007);
            yield $server->start([new CommandClient((string) $console->getArg("config")), "importServerSockets"]);
        } else {
            yield $server->start();
        }
        $this->server = $server;
        Loop::onReadable($this->ipcSock, function ($watcherId) {
            Loop::cancel($watcherId);
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
