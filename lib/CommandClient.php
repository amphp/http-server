<?php

namespace Aerys;

use Amp\ByteStream\ClosedException;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\File;
use Amp\Loop;
use Amp\Parser\Parser;
use Amp\Promise;
use Amp\Socket;
use function Amp\call;

class CommandClient {
    /** @var string */
    private $path;

    /** @var \Amp\Socket\ClientSocket */
    private $socket;
    private $lastSend;
    private $readMessages = [];

    /** @var \Amp\Parser\Parser */
    private $parser;

    public function __construct(string $config) {
        $this->path = self::socketPath($config);
        $this->parser = new Parser(self::parser($this->readMessages));
    }

    public static function socketPath(string $config) {
        // that base64_encode instead of the standard hex representation of sha1 is necessary to avoid overly long paths for unix domain sockets
        return \sys_get_temp_dir() . "/aerys_" . \strtr(\base64_encode(\sha1(Internal\selectConfigFile($config), true)), "+/", "-_").".tmp";
    }

    private function send($message): Promise {
        return $this->lastSend = call(function () use ($message) {
            if ($this->lastSend) {
                yield $this->lastSend;
            }

            if (!$this->socket) {
                $this->socket = yield new Coroutine(self::connect($this->path));
            }

            $message = \json_encode($message) . "\n";
            yield $this->socket->write($message);

            while (empty($this->readMessages)) {
                if (($chunk = yield $this->socket->read()) === null) {
                    $this->socket->close();
                    throw new ClosedException("Connection went away ...");
                }

                $this->parser->push($chunk);
            }

            $message = \array_shift($this->readMessages);

            if (isset($message["exception"])) {
                throw new \RuntimeException($message["exception"]);
            }

            return $message;
        });
    }

    private static function connect(string $path): \Generator {
        $unix = \in_array("unix", \stream_get_transports(), true);
        if ($unix) {
            $uri = "unix://$path.sock";
        } else {
            $uri = yield File\get($path);
        }
        return yield Socket\connect($uri);
    }

    private static function parser(array &$messages): \Generator {
        do {
            $messages[] = @\json_decode(yield "\n", true);
        } while (true);
    }

    public function __destruct() {
        if ($this->socket) {
            $this->socket->close();
        }
    }

    public function restart(): Promise {
        return $this->send(["action" => "restart"]);
    }

    public function stop(): Promise {
        return $this->send(["action" => "stop"]);
    }

    public function importServerSockets($addrCtxMap): Promise {
        return call(function () use ($addrCtxMap) {
            $reply = yield $this->send(["action" => "import-sockets", "addrCtxMap" => array_map(function ($context) { return $context["socket"]; }, $addrCtxMap)]);

            $sockets = $reply["count"];
            $serverSockets = [];
            $deferred = new Deferred;

            $sock = \socket_import_stream($this->socket->getResource());
            Loop::onReadable($this->socket->getResource(), function ($watcherId) use (&$serverSockets, &$sockets, $sock, $deferred, $addrCtxMap) {
                $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4]; // 4 == sizeof(int)
                if (!\socket_recvmsg($sock, $data)) {
                    Loop::cancel($watcherId);
                    $deferred->fail(new \RuntimeException("Server sockets could not be received from watcher process"));
                }
                $address = $data["iov"][0];
                $newSock = $data["control"][0]["data"][0];
                \socket_listen($newSock, $addrCtxMap[$address]["socket"]["backlog"] ?? 0);

                $newSocket = \socket_export_stream($newSock);
                \stream_context_set_option($newSocket, $addrCtxMap[$address]); // put eventual options like ssl back (per worker)
                $serverSockets[$address] = $newSocket;

                if (!--$sockets) {
                    Loop::cancel($watcherId);
                    $deferred->resolve($serverSockets);
                }
            });

            return $this->lastSend = $deferred->promise(); // Guards second watcher on socket by blocking calls to send()
        });
    }
}
