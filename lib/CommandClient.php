<?php

namespace Aerys;

class CommandClient {
    private $buf;
    private $promisors = [];
    private $path;
    private $sock;
    private $writer;
    private $written = 0;
    private $writeWatcher;

    public function __construct(string $config) {
        $this->path = self::socketPath($config);
        $this->writer = (new \ReflectionClass($this))->getMethod("writer")->getClosure($this);
    }

    public static function socketPath(string $config) {
        return sys_get_temp_dir()."/aerys_".str_replace(["/", ":"], "_", Bootstrapper::selectConfigFile($config)).".tmp";
    }

    private function send($msg): \Amp\Promise {
        if (!$this->sock) {
            $this->establish();
        } elseif (!$this->writeWatcher) {
            $this->writeWatcher = \Amp\onWritable($this->sock, $this->writer);
        }
        $msg = json_encode($msg);
        $this->buf .= pack("N", \strlen($msg)) . $msg;
        return ($this->promisors[\strlen($this->buf)] = new \Amp\Deferred)->promise();
    }

    private function establish() {
        \Amp\pipe(\Amp\file\get($this->path), 'Amp\Socket\connect')->when(function ($e, $sock) {
            if ($e) {
                $this->failAll();
                return;
            }
            $this->sock = $sock;
            $this->writeWatcher = \Amp\onWritable($sock, $this->writer);
        });
    }

    private function writer(string $watcherId, $socket) {
        $bytes = @fwrite($socket, $this->buf);
        if ($bytes === false) {
            if (!is_resource($socket) || @feof($socket)) {
                \Amp\cancel($this->writeWatcher);
                $this->sock = $this->writeWatcher = null;
                $this->establish();
            }
            return;
        }

        if ($bytes === \strlen($this->buf)) {
            \Amp\cancel($watcherId);
            $this->writeWatcher = null;
        }
        $this->written += $bytes;
        foreach ($this->promisors as $end => $deferred) {
            if ($end > $this->written) {
                break;
            }
            $deferred->succeed();
            unset($this->promisors[$end]);
        }
    }

    private function failAll() {
        if ($this->writeWatcher !== null) {
            \Amp\cancel($this->writeWatcher);
        }
        $this->sock = $this->writeWatcher = null;

        $promisors = $this->promisors;
        $this->promisors = [];
        foreach ($promisors as $deferred) {
            $deferred->fail(new \Exception("Couldn't write command, server failed."));
        }
    }

    public function restart(): \Amp\Promise {
        return $this->send(["action" => "restart"]);
    }

    public function __destruct() {
        @fclose($this->sock);
    }
}