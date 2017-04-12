<?php

namespace Aerys;

use Amp\Loop;

class CommandClient {
    use \Amp\CallableMaker;
    
    private $buf;
    private $deferreds = [];
    private $path;
    private $sock;
    private $writer;
    private $written = 0;
    private $writeWatcher;

    public function __construct(string $config) {
        $this->path = self::socketPath($config);
        $this->writer = $this->callableFromInstanceMethod("writer");
    }

    public static function socketPath(string $config) {
        // that base64_encode instead of the standard hex representation of sha1 is necessary to avoid overly long paths for unix domain sockets
        return sys_get_temp_dir()."/aerys_".strtr(base64_encode(sha1(Bootstrapper::selectConfigFile($config), true)), "+/", "-_").".tmp";
    }

    private function send($msg): \Amp\Promise {
        if (!$this->sock) {
            $this->establish();
        } elseif (!$this->writeWatcher) {
            $this->writeWatcher = Loop::onWritable($this->sock, $this->writer);
        }
        $msg = json_encode($msg);
        $this->buf .= pack("N", \strlen($msg)) . $msg;
        return ($this->deferreds[] = new \Amp\Deferred)->promise();
    }

    private function establish() {
        $unix = in_array("unix", \stream_get_transports(), true);
        if ($unix) {
            $promise = \Amp\Socket\connect("unix://$this->path.sock");
        } else {
            $promise = \Amp\Promise\pipe(\Amp\file\get($this->path), 'Amp\Socket\connect');
        }
        
        $promise->onResolve(function ($e, $sock) {
            if ($e) {
                $this->failAll();
                return;
            }
            $this->sock = $sock;
            $this->writeWatcher = Loop::onWritable($sock, $this->writer);
        });
    }

    private function writer(string $watcherId, $socket) {
        $bytes = @fwrite($socket, $this->buf);
        if ($bytes == 0) {
            if (!is_resource($socket) || @feof($socket)) {
                if($this->writeWatcher) {
                    Loop::cancel($this->writeWatcher);
                }
                $this->sock = $this->writeWatcher = null;
                $this->establish();
            }
            return;
        }

        if ($bytes === \strlen($this->buf)) {
            if($watcherId) {
                Loop::cancel($watcherId);
            }
            $this->writeWatcher = null;
        }
        $this->written += $bytes;
        foreach ($this->deferreds as $end => $deferred) {
            if ($end > $this->written) {
                break;
            }
            $deferred->resolve();
            unset($this->deferreds[$end]);
        }
    }

    private function failAll() {
        if ($this->writeWatcher !== null) {
            Loop::cancel($this->writeWatcher);
        }
        $this->sock = $this->writeWatcher = null;

        $deferreds = $this->deferreds;
        $this->deferreds = [];
        foreach ($deferreds as $deferred) {
            $deferred->fail(new \Exception("Couldn't write command, server failed."));
        }
    }

    public function restart(): \Amp\Promise {
        return $this->send(["action" => "restart"]);
    }

    public function stop(): \Amp\Promise {
        return $this->send(["action" => "stop"]);
    }

    public function __destruct() {
        @fclose($this->sock);
    }
}