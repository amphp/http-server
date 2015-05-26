<?php

namespace Aerys;

use Amp\{ Reactor, UvReactor, Promise, Success, function coroutine };

class DebugWatcher implements Watcher, ServerObserver {
    private $reactor;

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
        $server = yield from Bootstrapper::boot($this->reactor, $cliArgs, [$this]);
        foreach ($server->inspect()["boundAddresses"] as $address) {
            printf("Listening for HTTP traffic on %s ...\n", $address);
        }
    }

    /**
     * Observe server notifications
     *
     * @param \SplSubject $server
     * @return \Amp\Promise
     */
    public function update(\SplSubject $server): Promise {
        if ($server->state() === Server::STARTING) {
            $this->registerSignalHandler($server);
            register_shutdown_function($this->makeShutdownHandler($server));
        }

        return new Success;
    }

    private function registerSignalHandler(Server $server) {
        if (\php_sapi_name() === "phpdbg") {
            // phpdbg captures SIGINT so don't intercept these signals
            // if we're running inside the phpdbg debugger SAPI
            return;
        }

        $onSignal = $this->makeSignalHandler($server);

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, $onSignal);
            $this->reactor->onSignal(\UV::SIGTERM, $onSignal);
        } elseif (extension_loaded("pcntl")) {
            $this->reactor->repeat("pcntl_signal_dispatch", 1000);
            \pcntl_signal(\SIGINT, $onSignal);
            \pcntl_signal(\SIGTERM, $onSignal);
        } else {
            echo "Neither php-uv nor pcntl loaded; cannot catch process signals ...\n";
        }
    }

    private function makeSignalHandler(Server $server) {
        return coroutine(function() use ($server) {
            try {
                yield $server->stop();
                exit(0);
            } catch (\BaseException $e) {
                \error_log($e->__toString());
                exit(1);
            }
        }, $this->reactor);
    }

    private function makeShutdownHandler(Server $server) {
        return coroutine(function() use ($server) {
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

            try {
                yield $server->stop();
            } catch (\BaseException $uncaught) {
                \error_log($uncaught->__toString());
            } finally {
                exit(1);
            };
        }, $this->reactor);
    }
}
