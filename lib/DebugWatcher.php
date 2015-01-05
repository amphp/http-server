<?php

namespace Aerys;

use Amp\Reactor;
use Amp\UvReactor;
use Amp\LibeventReactor;

class DebugWatcher {
    private $reactor;
    private $server;
    private $hosts;

    public function __construct(Reactor $reactor, Server $server, HostGroup $hosts) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->hosts = $hosts;
        $this->server->setOption(Server::OP_DEBUG, true);
    }

    public function watch() {
        register_shutdown_function([$this, 'shutdown']);
        $this->registerInterruptHandler();
        $this->server->bind($this->hosts);
        yield $this->server->listen();
        foreach ($this->hosts->getBindableAddresses() as $addr) {
            $addr = substr(str_replace('0.0.0.0', '*', $addr), 6);
            printf("Listening for HTTP traffic on %s ...\n", $addr);
        }
    }

    private function registerInterruptHandler() {
        if (php_sapi_name() === 'phpdbg') {
            // phpdbg captures SIGINT so don't intercept these signals
            // if we're running inside the phpdbg debugger SAPI
            return;
        }

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, [$this, 'onInterrupt']);
        } elseif ($this->reactor instanceof LibeventReactor) {
            $this->reactor->onSignal($sigint = 2, [$this, 'onInterrupt']);
        } elseif (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, [$this, 'onInterrupt']);
        }
    }

    public function onInterrupt() {
        yield $this->server->stop();
        exit(0);
    }

    public function shutdown() {
        if (!$err = error_get_last()) {
            return;
        }

        switch ($err['type']) {
            case E_ERROR:
            case E_PARSE:
            case E_USER_ERROR:
            case E_CORE_ERROR:
            case E_CORE_WARNING:
            case E_COMPILE_ERROR:
            case E_COMPILE_WARNING:
                extract($err);
                printf("%s in %s on line %d\n", $message, $file, $line);
                $this->server->stop()->when(function() { exit(1); });
                break;
        }
    }
}
