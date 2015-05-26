<?php

namespace Aerys;

use Amp\{ Reactor, UvReactor, Promise, Success, function wait };

class DebugWatcher implements Watcher {
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
        $serverObservers = [$this->makeObserver()];
        $server = yield from Bootstrapper::boot($this->reactor, $cliArgs, $serverObservers);
        foreach ($server->inspect()["boundAddresses"] as $address) {
            printf("Listening for HTTP traffic on %s ...\n", $address);
        }
    }

    private function makeObserver(): ServerObserver {
        return new class($this->reactor) implements ServerObserver {
            private $reactor;

            public function __construct($reactor) {
                $this->reactor = $reactor;
            }

            public function update(\SplSubject $server): Promise {
                if ($server->state() === Server::STARTING) {
                    $this->registerSignalHandler($server);
                    $this->registerShutdownHandler($server);
                }

                return new Success;
            }

            public function registerSignalHandler(Server $server) {
                if (\php_sapi_name() === "phpdbg") {
                    // phpdbg captures SIGINT so don't intercept these signals
                    // if we're running inside the phpdbg debugger SAPI
                    return;
                }

                $onSignal = function() use ($server) {
                    try {
                        $server->stop()->when(function($error) {
                            if ($error) {
                                error_log($e->__toString());
                                exit(1);
                            } else {
                                exit(0);
                            }
                        });
                    } catch (\BaseException $e) {
                        error_log($e->__toString());
                        exit(1);
                    }
                };

                if ($this->reactor instanceof UvReactor) {
                    $this->reactor->onSignal(\UV::SIGINT, $onSignal);
                    $this->reactor->onSignal(\UV::SIGTERM, $onSignal);
                }
            }

            public function registerShutdownHandler(Server $server) {
                register_shutdown_function(function() use ($server) {
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
                        wait($server->stop());
                    } catch (\BaseException $e) {
                        \error_log($e->__toString());
                    } finally {
                        exit(1);
                    };
                });
            }
        };
    }
}
