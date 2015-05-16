<?php
namespace Aerys;

use Amp\{ Reactor, UvReactor };

class DebugWatcher {
    private $reactor;
    private $bootstrapper;

    public function __construct(Reactor $reactor, Bootstrapper $bootstrapper) {
        $this->reactor = $reactor;
        $this->bootstrapper = $bootstrapper;
    }

    public function watch(array $cliOptions): \Generator {
        $configFile = $cliOptions["config"];
        $forceDebug = $cliOptions["debug"];
        list($server, $addrCtxMap, $onClient) = $this->bootstrapper->boot($configFile, $forceDebug);
        yield $server->start($addrCtxMap, $onClient);
        $this->registerSignalHandler($server);
        $this->registerShutdownHandler($server);
        foreach (array_keys($addrCtxMap) as $addr) {
            $addr = substr(str_replace("0.0.0.0", "*", $addr), 6);
            printf("Listening for HTTP traffic on %s ...\n", $addr);
        }
    }

    private function registerSignalHandler(Server $server) {
        if (php_sapi_name() === "phpdbg") {
            // phpdbg captures SIGINT so don't intercept these signals
            // if we're running inside the phpdbg debugger SAPI
            return;
        }

        $signalHandler = function() use ($server) {
            try {
                \Amp\wait($server->stop(), $this->reactor);
                exit(0);
            } catch (\BaseException $e) {
                error_log($e->__toString());
                exit(1);
            }
        };

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, $signalHandler);
            $this->reactor->onSignal(\UV::SIGTERM, $signalHandler);
        } elseif (extension_loaded("pcntl")) {
            // @TODO This is buggy right now ... figure out what's wrong
            //pcntl_signal(SIGINT, $signalHandler);
            //pcntl_signal(SIGTERM, $signalHandler);
        }
    }

    public function onSignal() {
        try {
            \Amp\wait($this->server->stop(), $this->reactor);
            exit(0);
        } catch (\BaseException $e) {
            error_log($e->__toString());
            exit(1);
        }
    }

    private function registerShutdownHandler(Server $server) {
        register_shutdown_function(function() use ($server) {
            if (!$err = error_get_last()) {
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

            extract($err);
            error_log(sprintf("%s in %s on line %d\n", $message, $file, $line));

            $server->stop()->when(function($e) {
                if ($e) {
                    error_log($e->__toString());
                }
                exit(1);
            });
        });
    }
}
