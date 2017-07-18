<?php

namespace Aerys;

use Amp\ByteStream\ResourceOutputStream;
use Amp\Deferred;
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

    private function receiveServerSocketCallback($addrCtxMap) {
        return \Amp\call(function () use ($addrCtxMap) {
            // Logger must not be writing at the same time as we do here
            if (\method_exists($this->logger, "disableSending")) {
                yield $this->logger->disableSending();
            }

            $stream = new ResourceOutputStream($this->ipcSock);
            $json = json_encode(array_map(function ($context) {
                return $context["socket"];
            }, $addrCtxMap));
            yield $stream->write("\x1" . pack("N", \strlen($json)) . $json);

            if (\method_exists($this->logger, "enableSending")) {
                $this->logger->enableSending();
            }

            $deferred = new Deferred;
            $gen = (function () use (&$watcherId, $deferred, $addrCtxMap) {
                $serverSockets = [];

                // number of sockets followed by sockets with address in iov(ec)
                $data = "";
                do {
                    yield;
                    $data .= fread($this->ipcSock, 4 - \strlen($data));
                } while (\strlen($data) < 4);
                $sockets = unpack("Nlength", $data)["length"];

                $ipcSock = \socket_import_stream($this->ipcSock);
                while ($sockets--) {
                    yield;
                    $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4]; // 4 == sizeof(int)
                    if (!\socket_recvmsg($ipcSock, $data)) {
                        $deferred->fail(new \RuntimeException("Server sockets could not be received from watcher process"));
                        Loop::cancel($watcherId);
                    }
                    $address = $data["iov"][0];
                    $newSock = $data["control"][0]["data"][0];
                    \socket_listen($newSock, $addrCtxMap[$address]["socket"]["backlog"] ?? 0);

                    $newSocket = \socket_export_stream($newSock);
                    \stream_context_set_option($newSocket, $addrCtxMap[$address]); // put eventual options like ssl back (per worker)
                    $serverSockets[$address] = $newSocket;
                }

                Loop::cancel($watcherId);
                $deferred->resolve($serverSockets);
            })();
            $watcherId = Loop::onReadable($this->ipcSock, function () use ($gen) {
                $gen->next();
            });
            return $deferred->promise();
        });
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
            yield $server->start($this->callableFromInstanceMethod("receiveServerSocketCallback"));
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
