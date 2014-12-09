<?php

namespace Aerys;

use Amp\Reactor;
use Amp\UvReactor;
use Amp\LibeventReactor;

class DebugWatcher {
    private $reactor;
    private $server;

    public function __construct(Reactor $reactor = null) {
        $this->reactor = $reactor ?: \Amp\getReactor();
    }

    public function watch($configFile) {
        list($this->server, $hosts) = (new Bootstrapper($this->reactor))->boot($configFile);
        register_shutdown_function([$this, 'shutdown']);
        $this->registerInterruptHandler();
        $this->server->setDebugFlag(true);

        $this->server->bind($hosts);
        yield $this->server->listen();

        foreach ($hosts->getBindableAddresses() as $addr) {
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

        $interruptHandler = function() {
            $this->server->stop()->when(function(){ exit(0); });
        };

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, $interruptHandler);
        } elseif ($this->reactor instanceof LibeventReactor) {
            $this->reactor->onSignal($sigint = 2, $interruptHandler);
        } elseif (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, $interruptHandler);
        }
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
