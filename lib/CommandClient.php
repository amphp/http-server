<?php

namespace Aerys;

use Amp\File;
use Amp\Promise;
use Amp\Socket;
use function Amp\call;

class CommandClient {
    /** @var string */
    private $path;

    /** @var \Amp\Socket\ClientSocket */
    private $socket;

    public function __construct(string $config) {
        $this->path = self::socketPath($config);
    }

    public static function socketPath(string $config) {
        // that base64_encode instead of the standard hex representation of sha1 is necessary to avoid overly long paths for unix domain sockets
        return \sys_get_temp_dir() . "/aerys_" . \strtr(\base64_encode(\sha1(Bootstrapper::selectConfigFile($config), true)), "+/", "-_").".tmp";
    }

    private function send($message): Promise {
        return call(function () use ($message) {
            if (!$this->socket) {
                $this->socket = yield from $this->establish();
            }

            $message = \json_encode($message);
            $message = \pack("N", \strlen($message)) . $message;
            return yield $this->socket->write($message);
        });
    }

    private function establish(): \Generator {
        $unix = \in_array("unix", \stream_get_transports(), true);
        if ($unix) {
            return yield Socket\connect("unix://$this->path.sock");
        } else {
            $uri = yield File\get($this->path);
            return yield Socket\connect($uri);
        }
    }

    public function restart(): Promise {
        return $this->send(["action" => "restart"]);
    }

    public function stop(): Promise {
        return $this->send(["action" => "stop"]);
    }
}