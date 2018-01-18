<?php

namespace Aerys\Internal;

use Aerys\ErrorHandler;
use Aerys\Server;
use Aerys\ServerObserver;
use Amp\CallableMaker;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

class TimeReference implements ServerObserver {
    use CallableMaker;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var string */
    private $watcherId;

    /** @var callable[] */
    private $useCallbacks = [];

    /** @var int */
    private $currentTime;

    /** @var string */
    private $currentHttpDate;

    public function __construct(PsrLogger $logger) {
        $this->logger = $logger;
        $this->updateTime();
    }

    public function onStart(Server $server, PsrLogger $logger, ErrorHandler $errorHandler): Promise {
        $this->watcherId = Loop::repeat(1000, $this->callableFromInstanceMethod("updateTime"));
        $this->updateTime();
        return new Success;
    }

    public function onStop(Server $server): Promise {
        Loop::cancel($this->watcherId);
        $this->watcherId = null;
        return new Success;
    }

    /**
     * @return int
     */
    public function getCurrentTime(): int {
        return $this->currentTime;
    }

    /**
     * @return string
     */
    public function getCurrentDate(): string {
        return $this->currentHttpDate;
    }

    /**
     * Add a callback to invoke each time the time context updates.
     *
     * Callbacks are invoked with two parameters: currentTime and currentHttpDate.
     *
     * @param callable $useCallback
     * @return void
     */
    public function onTimeUpdate(callable $useCallback) {
        $this->useCallbacks[] = $useCallback;
        $this->tryUseCallback($useCallback);
    }

    /**
     * Updates the context with the current time.
     *
     * @return void
     */
    private function updateTime() {
        // Date string generation is (relatively) expensive. Since we only need HTTP
        // dates at a granularity of one second we're better off to generate this
        // information once per second and cache it.
        $this->currentTime = time();
        $this->currentHttpDate = gmdate("D, d M Y H:i:s", $this->currentTime) . " GMT";

        foreach ($this->useCallbacks as $useCallback) {
            $this->tryUseCallback($useCallback);
        }
    }

    private function tryUseCallback(callable $useCallback) {
        try {
            $useCallback($this->currentTime, $this->currentHttpDate);
        } catch (\Throwable $uncaught) {
            $this->logger->critical($uncaught);
        }
    }
}
