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
    private $stopPromisor;
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

        if ($this->stopPromisor) {
            \Amp\cancel($this->writeWatcherId);
            $promisor = $this->stopPromisor;
            $this->stopPromisor = null;
            $promisor->succeed();
        } else {
            \Amp\disable($this->writeWatcherId);
        }
    }

    private function onDeadIpcSock() {
        $this->isDead = true;
        $this->writeBuffer = "";
        $this->writeQueue = [];
        \Amp\cancel($this->writeWatcherId);
        if ($this->stopPromisor) {
            $promisor = $this->stopPromisor;
            $this->stopPromisor = null;
            $promisor->succeed();
        }
    }

    public function stop(): Promise {
        if ($this->isDead || $this->writeBuffer === "") {
            return new Success;
        } else {
            $this->stopPromisor = new Deferred;
            return $this->stopPromisor->promise();
        }
    }
}
