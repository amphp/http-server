<?php

namespace Aerys;

use Amp\{
    Promise,
    Success,
    Deferred
};

class IpcLogger extends Logger {
    private $ipcSock;
    private $writeWatcherId;
    private $writeQueue = [];
    private $writeBuffer = "";
    private $isDead;

    public function __construct(Console $console, $ipcSock) {
        if ($console->isArgDefined("color")) {
            $this->setAnsify($console->getArg("color"));
        }
        $level = $console->getArg("log");
        $level = isset(self::LEVELS[$level]) ? self::LEVELS[$level] : $level;
        $this->setOutputLevel($level);

        $onWritable = $this->makePrivateCallable("onWritable");
        $this->ipcSock = $ipcSock;
        stream_set_blocking($ipcSock, false);
        $this->writeWatcherId = \Amp\onWritable($ipcSock, $onWritable, [
            "enable" => false,
        ]);
    }

    private function makePrivateCallable(string $method): \Closure {
        return (new \ReflectionClass($this))->getMethod($method)->getClosure($this);
    }

    protected function output(string $message) {
        if (empty($this->isDead)) {
            $this->writeQueue[] = pack("N", \strlen($message));
            $this->writeQueue[] = $message;
            \Amp\enable($this->writeWatcherId);
        }
    }

    private function onWritable() {
        if ($this->isDead) {
            return;
        }

        if ($this->writeBuffer === "") {
            $this->writeBuffer = implode("", $this->writeQueue);
            $this->writeQueue = [];
        }

        $bytes = @fwrite($this->ipcSock, $this->writeBuffer);
        if ($bytes === false) {
            $this->onDeadIpcSock();
            return;
        }

        if ($bytes !== \strlen($this->writeBuffer)) {
            $this->writeBuffer = substr($this->writeBuffer, $bytes);
            return;
        }

        if ($this->writeQueue) {
            $this->writeBuffer = implode("", $this->writeQueue);
            $this->writeQueue = [];
            return;
        }

        $this->writeBuffer = "";

        \Amp\disable($this->writeWatcherId);
    }

    private function onDeadIpcSock() {
        $this->isDead = true;
        $this->writeBuffer = "";
        $this->writeQueue = [];
        \Amp\cancel($this->writeWatcherId);
    }

    public function flush() { // BLOCKING
        if ($this->isDead || ($this->writeBuffer === "" && empty($this->writeQueue))) {
            return;
        }

        stream_set_blocking($this->ipcSock, true);
        $this->onWritable();
        stream_set_blocking($this->ipcSock, false);
    }
}
