<?php

namespace Aerys;

use Amp\CallableMaker;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

class TimeReference implements ServerObserver {
    use CallableMaker;

    /** @var string */
    private $watcherId;

    /** @var callable[] */
    private $callbacks = [];

    /** @var int */
    private $currentTime;

    /** @var string */
    private $currentHttpDate;

    public function __construct() {
        $this->updateTime();
    }

    /** @inheritdoc */
    public function onStart(Server $server): Promise {
        $this->watcherId = Loop::repeat(1000, $this->callableFromInstanceMethod("updateTime"));
        $this->updateTime();

        return new Success;
    }

    /** @inheritdoc */
    public function onStop(Server $server): Promise {
        Loop::cancel($this->watcherId);

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
     * Callbacks SHOULD NOT throw. Any errors will bubble up to the event loop.
     *
     * @param callable $callback
     */
    public function onTimeUpdate(callable $callback) {
        $this->callbacks[] = $callback;
        $callback($this->currentTime, $this->currentHttpDate);
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

        foreach ($this->callbacks as $callback) {
            $callback($this->currentTime, $this->currentHttpDate);
        }
    }
}
