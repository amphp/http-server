<?php

namespace Amp\Http\Server;

use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Success;

class IpcLogger extends Logger {
    private $ipcSock;
    private $lastWrite;

    public function __construct(Console $console, Socket $ipcSock) {
        $this->setAnsify($console->getArg("color"));
        $level = $console->getArg("log");
        $level = isset(self::LEVELS[$level]) ? self::LEVELS[$level] : $level;
        $this->setOutputLevel($level);

        $this->ipcSock = $ipcSock;
    }

    protected function output(string $message) {
        if (!$this->ipcSock) {
            return;
        }

        $message = pack("N", \strlen($message)) . $message;
        $this->lastWrite = $this->ipcSock->write($message);
        $this->lastWrite->onResolve(function ($error) {
            if ($error) {
                $this->ipcSock->close();
                $this->ipcSock = null;
            }
        });
    }

    public function flush(): Promise {
        if ($this->ipcSock) {
            $this->ipcSock->close();
            $this->ipcSock = null;
        }

        return $this->lastWrite ?? new Success;
    }
}
