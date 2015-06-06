<?php

namespace Aerys;

use Amp\{
    Reactor,
    Promise,
    Success,
    Struct
};

class TimeContext implements ServerObserver {
    private $reactor;
    private $logger;
    private $watcherId;
    private $currentTime;
    private $currentHttpDate;
    private $useCallbacks;

    final public function __construct(Reactor $reactor, Logger $logger) {
        $this->reactor = $reactor;
        $this->logger = $logger;
    }

    final public function update(Server $server): Promise {
        switch ($server->state()) {
            case Server::STARTED:
                $this->watcherId = $this->reactor->repeat([$this, "updateTime"], 1000);
                $this->updateTime();
                break;
            case Server::STOPPED:
                $this->reactor->cancel($this->watcherId);
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
    final public function use(callable $useCallback) {
        $this->useCallbacks[] = $useCallback;
    }

    /**
     * Updates the context with the current time
     *
     * @return void
     */
    final public function updateTime() {
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
        } catch (\BaseException $uncaught) {
            $this->logger->critical($uncaught);
        }
    }

    /**
     * Allow retrieval of "public" properties
     *
     * @param string $property
     * @return mixed Returns the value of the requested property
     * @throws \DomainException If an unknown property is requested
     */
    final public function __get(string $property) {
        if ($property === "currentTime" || $property === "currentHttpDate") {
            return $this->{$property};
        } else {
            throw new \DomainException(
            "Unknown property: " . __CLASS__ . "::\${$property}"
        );
        }
    }

    /**
     * Prevent external code from modifying our "public" time/date properties
     *
     * @param string $property
     * @param mixed $value
     * @throws \DomainException
     */
    final public function __set(string $property, $value) {
        throw new \DomainException(
            "Unknown property: " . __CLASS__ . "::\${$property}"
        );
    }
}
