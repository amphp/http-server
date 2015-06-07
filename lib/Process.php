<?php

namespace Aerys;

use Amp\{
    Reactor,
    UvReactor,
    function coroutine,
    function resolve,
    function wait
};

abstract class Process {
    private $reactor;
    private $logger;
    private $exitCode = 0;
    private $isStopping = false;

    abstract protected function doStart(Console $console): \Generator;
    abstract protected function doStop(): \Generator;

    public function __construct(Reactor $reactor, Logger $logger) {
        $this->reactor = $reactor;
        $this->logger = $logger;
    }

    /**
     * Start the process
     *
     * @param \Aerys\Console $console
     * @return \Generator
     */
    final public function start(Console $console): \Generator {
        try {
            if (empty($this->isStopping)) {
                yield from $this->doStart($console);
            }
            // Once we make it this far we no longer want to terminate
            // the process in the event of an uncaught exception inside
            // the event reactor -- log it instead.
            $this->reactor->onError([$this->logger, "critical"]);
        } catch (\BaseException $uncaught) {
            $this->exitCode = 1;
            yield $this->logger->critical($uncaught);
            $this->exit();
        }
    }

    /**
     * Stop the process
     *
     * @return \Generator
     */
    final public function stop(): \Generator {
        try {
            if (empty($this->isStopping)) {
                $this->isStopping = true;
                yield from $this->doStop();
            }
        } catch (\BaseException $uncaught) {
            $this->exitCode = 1;
            yield $this->logger->critical($uncaught);
        } finally {
            $this->exit();
        }
    }

    public function registerSignalHandler() {
        if (php_sapi_name() === "phpdbg") {
            // phpdbg captures SIGINT so don't bother inside the debugger
            return;
        }

        $onSignal = coroutine([$this, "stop"], $this->reactor);

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, $onSignal);
            $this->reactor->onSignal(\UV::SIGTERM, $onSignal);
        } elseif (extension_loaded("pcntl")) {
            $this->reactor->repeat("pcntl_signal_dispatch", 1000);
            pcntl_signal(\SIGINT, $onSignal);
            pcntl_signal(\SIGTERM, $onSignal);
        }
    }

    public function registerShutdownHandler() {
        register_shutdown_function(function() {
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

            $this->exitCode = 1;
            $msg = "{$err["message"]} in {$err["file"]} on line {$err["line"]}";
            $gen = function($msg) {
                yield $this->logger->critical($msg);
                yield from $this->stop();
            };
            $promise = resolve($gen($msg), $this->reactor);
            wait($promise, $this->reactor);
        });
    }

    public function registerErrorHandler() {
        set_error_handler(coroutine(function($errno, $msg, $file, $line) {
            if (!(error_reporting() & $errno)) {
                return;
            }

            $msg = "{$msg} in {$file} on line {$line}";

            switch ($errno) {
                case E_ERROR:
                case E_PARSE:
                case E_USER_ERROR:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_RECOVERABLE_ERROR:
                    yield $this->logger->error($msg);
                    break;
                case E_CORE_WARNING:
                case E_COMPILE_WARNING:
                case E_WARNING:
                case E_USER_WARNING:
                    yield $this->logger->warning($msg);
                    break;
                case E_NOTICE:
                case E_USER_NOTICE:
                case E_DEPRECATED:
                case E_USER_DEPRECATED:
                case E_STRICT:
                    yield $this->logger->notice($msg);
                    break;
                default:
                    yield $this->logger->warning($msg);
                    break;
            }
        }, $this->reactor));
    }

    /**
     * This function only exists as protected so we can test for its invocation
     */
    protected function exit() {
        exit($this->exitCode);
    }
}
