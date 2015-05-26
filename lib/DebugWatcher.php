<?php

namespace Aerys;

use Amp\{ Reactor, UvReactor, function coroutine };

class DebugWatcher {
    private $reactor;
    private $isStopping;

    /**
     * @param \Amp\Reactor $reactor
     */
    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }

    /**
     * Watch/manage a server instance
     *
     * @param \Amp\Reactor $reactor
     * @param array $cliArgs
     * @return \Generator
     */
    public function watch(array $cliArgs): \Generator {
        $bootArr = Bootstrapper::boot($this->reactor, $cliArgs);
        list($server, $options, $addrCtxMap, $rfc7230Server) = $bootArr;
        $this->registerSignalHandler($server);
        $this->registerShutdownHandler($server);
        yield $server->start($addrCtxMap, [$rfc7230Server, "import"]);
        foreach ($server->inspect()["boundAddresses"] as $address) {
            printf("Listening for HTTP traffic on %s ...\n", $address);
        }
    }

    private function registerSignalHandler($server) {
        if (\php_sapi_name() === "phpdbg") {
            // phpdbg captures SIGINT so don't intercept these signals
            // if we're running inside the debugger SAPI
            return;
        }

        $stopper = coroutine(function() use ($server) {
            if ($this->isStopping) {
                return;
            }
            $this->isStopping = true;
            try {
                yield $server->stop();
                exit(0);
            } catch (\BaseException $e) {
                error_log($e->__toString());
                exit(1);
            }
        }, $this->reactor);

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, $stopper);
            $this->reactor->onSignal(\UV::SIGTERM, $stopper);
        } elseif (extension_loaded("pcntl")) {
            $this->reactor->repeat("pcntl_signal_dispatch", 1000);
            \pcntl_signal(\SIGINT, $stopper);
            \pcntl_signal(\SIGTERM, $stopper);
        } else {
            echo "Cannot catch process control signals; php-uv or pcntl required ...\n";
        }
    }

    private function registerShutdownHandler($server) {
        register_shutdown_function(coroutine(function() use ($server) {
            if (!$err = \error_get_last()) {
                return;
            }

            switch ($err["type"]) {
                case E_ERROR:
                case E_PARSE:
                case E_USER_ERROR:
                case E_CORE_ERROR:
                case E_CORE_WARNING:
                case E_COMPILE_ERROR:
                case E_COMPILE_WARNING:
                case E_RECOVERABLE_ERROR:
                    break;
                default:
                    return;
            }

            \extract($err);
            \error_log(sprintf("%s in %s on line %d\n", $message, $file, $line));

            if ($this->isStopping) {
                return;
            }
            $this->isStopping = true;
            try {
                yield $server->stop();
            } catch (\BaseException $uncaught) {
                \error_log($uncaught->__toString());
            } finally {
                exit(1);
            };
        }, $this->reactor));
    }
}
