<?php

namespace Aerys;

use Amp\{
    Reactor,
    UvReactor,
    function coroutine
};

use League\CLImate\CLImate;

class DebugWatcher {
    private $reactor;
    private $logger;
    private $bootstrapper;
    private $isStopping;

    /**
     * Watch/manage a debug server instance in the current process
     *
     * @param \Amp\Reactor $reactor
     * @param \Aerys\Logger $logger
     * @param \Aerys\Bootstrapper $bootstrapper
     * @return \Generator
     */
    public function __construct(Reactor $reactor, Logger $logger, Bootstrapper $bootstrapper = null) {
        $this->reactor = $reactor;
        $this->logger = $logger;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
    }

    /**
     * Run a debug server instance in the current process
     *
     * @param \CLImate $climate
     * @return \Generator
     */
    public function watch(CLImate $climate): \Generator {
        $bootArr = $this->bootstrapper->boot($this->reactor, $this->logger, $climate);
        list($server, $options, $addrCtxMap, $rfc7230Server) = $bootArr;
        $this->registerSignalHandler($server);
        $this->registerShutdownHandler($server);
        $this->registerErrorHandler();
        yield $server->start($addrCtxMap, [$rfc7230Server, "import"]);
        foreach ($server->inspect()["boundAddresses"] as $address) {
            $this->logger->info("Listening for HTTP traffic on {$address}");
        }
    }

    private function registerSignalHandler($server) {
        if (php_sapi_name() === "phpdbg") {
            // phpdbg captures SIGINT so don't bother inside the debugger
            return;
        }

        $onSignal = coroutine(function() use ($server) {
            if ($this->isStopping) {
                return;
            }
            $this->isStopping = true;
            try {
                yield $server->stop();
                exit(0);
            } catch (\BaseException $uncaught) {
                $this->logger->critical($uncaught->__toString());
                exit(1);
            }
        }, $this->reactor);

        if ($this->reactor instanceof UvReactor) {
            $this->reactor->onSignal(\UV::SIGINT, $onSignal);
            $this->reactor->onSignal(\UV::SIGTERM, $onSignal);
        } elseif (extension_loaded("pcntl")) {
            $this->reactor->repeat("pcntl_signal_dispatch", 1000);
            pcntl_signal(\SIGINT, $onSignal);
            pcntl_signal(\SIGTERM, $onSignal);
        } else {
            $this->logger->debug("Process control signals unavailable; php-uv or pcntl required");
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

            extract($err);
            $this->logger->critical("{$message} in {$file} on line {$line}");

            if ($this->isStopping) {
                return;
                exit(1);
            }
            $this->isStopping = true;
            try {
                yield $server->stop();
            } catch (\BaseException $uncaught) {
                $this->logger->critical($uncaught->__toString());
            } finally {
                exit(1);
            };
        }, $this->reactor));
    }

    private function registerErrorHandler() {
        set_error_handler(function($errno, $msg, $file, $line) {
            if (!(error_reporting() & $errno)) {
                return;
            }

            $msg = "{$msg} in {$file} on line {$line}";

            switch ($errno) {
                case E_ERROR:
                case E_PARSE:
                    $this->logger->critical($msg);
                    break;
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
}
