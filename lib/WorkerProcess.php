<?php

namespace Aerys;

use Amp\ByteStream\InputStream;
use Amp\Coroutine;
use Amp\Loop;
use Amp\Promise;
use Psr\Log\LoggerInterface as PsrLogger;

class WorkerProcess extends Process {
    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Amp\Socket\Socket */
    private $ipcSock;

    /** @var \Aerys\Server */
    private $server;

    public function __construct(PsrLogger $logger, InputStream $ipcSock) {
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
        if (\extension_loaded("sockets") && PHP_VERSION_ID > 70007 && stripos(PHP_OS, "WIN") !== 0) {
            // needs socket_export_stream() and no Windows
            $config = (string) $console->getArg("config");
            $getSocketTransferImporter = [new CommandClient($config), "importServerSockets"];

            if (in_array(strtolower(PHP_OS), ["darwin", "freebsd"])) { // the OS not supporting *round-robin* so_reuseport client distribution
                $importer = $getSocketTransferImporter;
            } else {
                // prefer local so_reuseport binding in favor of transferring tcp server sockets (i.e. only use socket transfer for unix domain sockets)
                $importer = \Amp\coroutine(function (array $addrContextMap, callable $socketBinder) use ($getSocketTransferImporter) {
                    $boundSockets = $unixAddrContextMap = [];

                    foreach ($addrContextMap as $address => $context) {
                        if (!strncmp("unix://", $address, 7)) {
                            $unixAddrContextMap[$address] = $context;
                        } else {
                            $boundSockets[$address] = $socketBinder($address, $context);
                        }
                    }

                    return $unixAddrContextMap ? $boundSockets + yield $getSocketTransferImporter($unixAddrContextMap) : $boundSockets;
                });
            }

            yield $server->start($importer);
        } else {
            yield $server->start();
        }

        $this->server = $server;

        Promise\rethrow(new Coroutine($this->listenForStop()));
    }

    private function listenForStop(): \Generator {
        $this->ipcSock->unreference();
        yield $this->ipcSock->read();

        if ($this->server) {
            yield $this->stop();
        }
    }

    protected function doStop(): \Generator {
        if ($this->server) {
            yield $this->server->stop();
        }

        $this->server = null;

        if ($this->logger instanceof IpcLogger) {
            yield $this->logger->flush();
        }
    }
}
