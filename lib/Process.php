<?php

namespace Aerys;

use Amp\Loop;
use Amp\Promise;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;

abstract class Process {
    const STOPPED = 0;
    const STARTED = 1;
    const STOPPING = 2;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var int */
    private $exitCode = 0;

    /** @var int */
    private $state = self::STOPPED;

    abstract protected function doStart(Console $console): \Generator;
    abstract protected function doStop(): \Generator;

    public function __construct(PsrLogger $logger) {
        $this->logger = $logger;
    }

    /**
     * Start the process.
     *
     * @param \Aerys\Console $console
     *
     * @return \Amp\Promise<null>
     */
    public function start(Console $console): Promise {
        return call(function () use ($console) {
            try {
                if ($this->state) {
                    throw new \Error(
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
                // the event loop -- log it instead.
                Loop::setErrorHandler([$this->logger, "critical"]);
            } catch (\Throwable $uncaught) {
                $this->exitCode = 1;
                $this->logger->critical($uncaught);
                if (method_exists($this->logger, "flush")) {
                    $this->logger->flush();
                }
                static::exit();
            }
        });
    }

    /**
     * Stop the process.
     *
     * @return \Amp\Promise<null>
     */
    public function stop(): Promise {
        return call(function () {
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
        });
    }

    private function registerSignalHandler() {
        if (PHP_SAPI === "phpdbg") {
            // phpdbg captures SIGINT so don't bother inside the debugger
            return;
        }

        $onSignal = [$this, "stop"];

        $loop = Loop::get()->getHandle();
        if (is_resource($loop) && get_resource_type($loop) == "uv_loop") {
            Loop::unreference(Loop::onSignal(\UV::SIGINT, $onSignal));
            Loop::unreference(Loop::onSignal(\UV::SIGTERM, $onSignal));
        } elseif (extension_loaded("pcntl")) {
            Loop::unreference(Loop::onSignal(\SIGINT, $onSignal));
            Loop::unreference(Loop::onSignal(\SIGTERM, $onSignal));
        }
    }

    private function registerShutdownHandler() {
        register_shutdown_function(function () {
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

            $previous = Loop::get();

            try {
                Loop::set((new Loop\DriverFactory)->create());
                Loop::run(function () use ($msg) {
                    $this->logger->critical($msg);
                    return $this->stop();
                });
            } finally {
                Loop::set($previous);
            }
        });
    }

    private function registerErrorHandler() {
        set_error_handler(function ($errno, $msg, $file, $line) {
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
        });
    }

    /**
     * This function only exists as protected so we can test for its invocation.
     */
    protected function exit() {
        exit($this->exitCode);
    }
}
