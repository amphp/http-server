<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

final class SystemTimeReference implements TimeReference, ServerObserver
{
    /** @var string */
    private $watcherId;

    /** @var callable[] */
    private $callbacks = [];

    /** @var int */
    private $currentTime;

    /** @var string */
    private $currentHttpDate;

    public function __construct()
    {
        $this->updateTime();
    }

    /** @inheritdoc */
    public function onStart(Server $server): Promise
    {
        $this->watcherId = Loop::repeat(1000, \Closure::fromCallable([$this, 'updateTime']));
        $this->updateTime();

        return new Success;
    }

    /** @inheritdoc */
    public function onStop(Server $server): Promise
    {
        Loop::cancel($this->watcherId);

        return new Success;
    }

    /** @inheritdoc */
    public function getCurrentTime(): int
    {
        return $this->currentTime;
    }

    /** @inheritdoc */
    public function getCurrentDate(): string
    {
        return $this->currentHttpDate;
    }

    /** @inheritdoc */
    public function onTimeUpdate(callable $callback): void
    {
        $this->callbacks[] = $callback;
        $callback($this->currentTime, $this->currentHttpDate);
    }

    /**
     * Updates the context with the current time.
     */
    private function updateTime(): void
    {
        // Date string generation is (relatively) expensive. Since we only need HTTP
        // dates at a granularity of one second we're better off to generate this
        // information once per second and cache it.
        $this->currentTime = \time();
        $this->currentHttpDate = \gmdate("D, d M Y H:i:s", $this->currentTime) . " GMT";

        foreach ($this->callbacks as $callback) {
            $callback($this->currentTime, $this->currentHttpDate);
        }
    }
}
