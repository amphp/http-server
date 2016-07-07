<?php

namespace Aerys;

use Amp\{
    UvReactor,
    function coroutine,
    function resolve
};
use Psr\Log\LoggerInterface as PsrLogger;

abstract class Process {
    const STOPPED = 0;
    const STARTED = 1;
    const STOPPING = 2;

    private $logger;
    private $exitCode = 0;
    private $state = self::STOPPED;

    abstract protected function doStart(Console $console): \Generator;
    abstract protected function doStop(): \Generator;

    public function __construct(PsrLogger $logger) {
        $this->logger = $logger;
    }

    /**
     * Start the process
     *
     * @param \Aerys\Console $console
     * @return \Generator
     */
    public function start(Console $console): \Generator {
        try {
            if ($this->state) {
                throw new \LogicException(
                    "A process may only be started once"
                );
            }

            $this->registerSignalHandler();
            $this->registerShutdownHandler();
            $this->registerErrorHandler();

            $this->state = self::STARTED;

            yield from $this->doStart($console);

            // Once we make it this far we no longer want to terminate
            // the process in the event of an uncaught exception inside
            // the event reactor -- log it instead.
            \Amp\onError([$this->logger, "critical"]);
        } catch (\Throwable $uncaught) {
            $this->exitCode = 1;
            $this->logger->critical($uncaught);
            static::exit();
        }
    }

    /**
     * Stop the process
     *
     * @return \Generator
     */
    public function stop(): \Generator {
        try {
            switch ($this->state) {
                case self::STOPPED:
                case self::STOPPING:
                    return;
                case self::STARTED:
                    break;
            }
            $this->state = self::STOPPING;
            yield from $this->doStop();
        } catch (\Throwable $uncaught) {
            $this->exitCode = 1;
            $this->logger->critical($uncaught);
        } finally {
            static::exit();
        }
    }

    private function registerSignalHandler() {
        if (php_sapi_name() === "phpdbg") {
            // phpdbg captures SIGINT so don't bother inside the debugger
            return;
        }

        $onSignal = coroutine([$this, "stop"]);

        if (\Amp\reactor() instanceof UvReactor) {
            \Amp\onSignal(\UV::SIGINT, $onSignal, ["keep_alive" => false]);
            \Amp\onSignal(\UV::SIGTERM, $onSignal, ["keep_alive" => false]);
        } elseif (extension_loaded("pcntl")) {
            \Amp\repeat("pcntl_signal_dispatch", 1000, ["keep_alive" => false]);
            pcntl_signal(\SIGINT, $onSignal);
            pcntl_signal(\SIGTERM, $onSignal);
        }
    }

    private function registerShutdownHandler() {
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
            // FIXME: Fatal error: Uncaught LogicException: Cannot run() recursively; event reactor already active
            \Amp\run(function() use ($msg) {
                $this->logger->critical($msg);
                yield from $this->stop();
            });
        });
    }

    private function registerErrorHandler() {
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
                    $this->logger->error($msg);
                    break;
                case E_CORE_WARNING:
                case E_COMPILE_WARNING:
                case E_WARNING:
                case E_USER_WARNING:
                    $this->logger->warning($msg);
                    break;
                case E_NOTICE:
                case E_USER_NOTICE:
                case E_DEPRECATED:
                case E_USER_DEPRECATED:
                case E_STRICT:
                    $this->logger->notice($msg);
                    break;
                default:
                    $this->logger->warning($msg);
                    break;
            }
        }));
    }

    /**
     * This function only exists as protected so we can test for its invocation
     */
    protected function exit() {
        exit($this->exitCode);
    }
}
