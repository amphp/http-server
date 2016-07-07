<?php

namespace Aerys;

use Amp\{
    Promise,
    Success,
    Struct
};
use Psr\Log\LoggerInterface as PsrLogger;

class Ticker implements ServerObserver {
    use Struct;

    private $logger;
    private $watcherId;
    private $useCallbacks;

    public $currentTime;
    public $currentHttpDate;

    public function __construct(PsrLogger $logger) {
        $this->logger = $logger;
    }

    public function update(Server $server): Promise {
        switch ($server->state()) {
            case Server::STARTED:
                $this->watcherId = \Amp\repeat([$this, "updateTime"], 1000);
                $this->updateTime();
                break;
            case Server::STOPPED:
                \Amp\cancel($this->watcherId);
                $this->watcherId = null;
                break;
        }

        return new Success;
    }

    /**
     * Add a callback to invoke each time the time context updates
     *
     * Callbacks are invoked with two parameters: currentTime and currentHttpDate.
     *
     * @param callable $useCallback
     * @return void
     */
    public function use(callable $useCallback) {
        $this->useCallbacks[] = $useCallback;
    }

    /**
     * Updates the context with the current time
     *
     * @return void
     */
    public function updateTime() {
        // Date string generation is (relatively) expensive. Since we only need HTTP
        // dates at a granularity of one second we're better off to generate this
        // information once per second and cache it.
        $now = (int) round(microtime(1));
        $this->currentTime = $now;
        $this->currentHttpDate = gmdate("D, d M Y H:i:s", $now) . " GMT";
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
