<?php

namespace Aerys;

use Amp\ByteStream\ClosedException;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\File;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use function Amp\call;

class CommandClient {
    /** @var string */
    private $path;

    /** @var \Amp\Socket\ClientSocket */
    private $socket;
    private $socketPromise;
    private $readDeferreds = [];

    public function __construct(string $config) {
        $this->path = self::socketPath($config);
    }

    public static function socketPath(string $config) {
        // that base64_encode instead of the standard hex representation of sha1 is necessary to avoid overly long paths for unix domain sockets
        return \sys_get_temp_dir() . "/aerys_" . \strtr(\base64_encode(\sha1(selectConfigFile($config), true)), "+/", "-_").".tmp";
    }

    private function send($message): Promise {
        return call(function () use ($message) {
            if (!$this->socket) {
                if (!$this->socketPromise) {
                    new Coroutine(self::start($this->path, $this->socket, $this->socketPromise, $this->readDeferreds));
                }

                yield $this->socketPromise;
            }

            try {
                $message = \json_encode($message) . "\n";
                yield $this->socket->write($message);
            } catch (ClosedException $e) {
                $this->socket = null;
                new Coroutine(self::start($this->path, $this->socket, $this->socketDeferred, $this->readDeferreds));
                yield $this->socketPromise;
                yield $this->socket->write($message);
            }

            $promise = ($this->readDeferreds[] = new Deferred)->promise();


            return $promise;
        });
    }

    private static function start($path, &$socket, &$socketPromise, &$readDeferreds) {
        $socketDeferred = new Deferred;
        $socketPromise = $socketDeferred->promise();

        $unix = \in_array("unix", \stream_get_transports(), true);
        if ($unix) {
            $uri = "unix://$path.sock";
        } else {
            $uri = yield File\get($path);
        }
        $socket = yield Socket\connect($uri);

        $socketDeferred->resolve();

        $parser = new \Amp\Parser\Parser((function () use (&$readDeferreds) {
            do {
                $message = @\json_decode(yield "\n", true);
                if (isset($message["exception"])) {
                    \current($readDeferreds)->fail(new \RuntimeException($message["exception"]));
                } else {
                    \current($readDeferreds)->resolve($message);
                }
                unset($readDeferreds[\key($readDeferreds)]);
            } while (true);
        })());

        while (($chunk = yield $socket->read()) !== null) {
            $parser->push($chunk);
        }

        $socket = null;
        $deferreds = $readDeferreds;
        $readDeferreds = [];
        foreach ($deferreds as $deferred) {
            $deferred->fail(new ClosedException("Connection went away ..."));
        }
    }

    function __destruct() {
        if ($this->socket) {
            if ($this->readDeferreds) {
                end($this->readDeferreds)->promise()->onResolve([$this->socket, "close"]);
                reset($this->readDeferreds);
            } else {
                $this->socket->close();
            }
        }
    }

    public function restart(): Promise {
        return $this->send(["action" => "restart"]);
    }

    public function stop(): Promise {
        return $this->send(["action" => "stop"]);
    }

    public function importServerSockets($addrCtxMap): Promise {
        return \Amp\call(function() use ($addrCtxMap) {
            $reply = yield $this->send(["action" => "import-sockets", "addrCtxMap" => array_map(function ($context) { return $context["socket"]; }, $addrCtxMap)]);

            // replace $this->socket temporarily by a dummy read() which will later be resolved to $this->socket->read() in order to avoid simultaneous read watchers here
            $socket = $this->socket;
            $this->socket = new class {
                public $deferred;
                function read() {
                    return $this->deferred;
                }
            };

            $sockets = $reply["count"];
            $serverSockets = [];

            $watcherId = Loop::onReadable($socket->getResource(), function () use (&$deferred) {
                $deferred->resolve();
            });

            $sock = \socket_import_stream($socket->getResource());
            while ($sockets--) {
                yield ($deferred = new Deferred)->promise();

                $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4]; // 4 == sizeof(int)
                if (!\socket_recvmsg($sock, $data)) {
                    Loop::cancel($watcherId);
                    throw new \RuntimeException("Server sockets could not be received from watcher process");
                }
                $address = $data["iov"][0];
                $newSock = $data["control"][0]["data"][0];
                \socket_listen($newSock, $addrCtxMap[$address]["socket"]["backlog"] ?? 0);

                $newSocket = \socket_export_stream($newSock);
                \stream_context_set_option($newSocket, $addrCtxMap[$address]); // put eventual options like ssl back (per worker)
                $serverSockets[$address] = $newSocket;
            }

            Loop::cancel($watcherId);

            $this->socket->deferred = $deferred = new Deferred;
            $this->socket = $socket;
            $deferred->resolve($socket->read());

            return $serverSockets;
        });
    }
}
