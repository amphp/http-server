<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\Driver\Client;
use Revolt\EventLoop;

/** @internal */
final class TimeoutQueue
{
    private readonly TimeoutCache $timeoutCache;

    private ?string $callbackId = null;

    /** @var array<string, array{Client, int, \Closure(Client, int):void}> */
    private array $callbacks = [];

    public function __construct()
    {
        $this->timeoutCache = new TimeoutCache;
    }

    public function __destruct()
    {
        if ($this->callbackId !== null) {
            EventLoop::cancel($this->callbackId);
        }
    }

    /**
     * Insert a client and stream pair. The given closure is invoked when the timeout elapses.
     *
     * @param \Closure(Client, int):void $onTimeout
     */
    public function insert(Client $client, int $streamId, \Closure $onTimeout, int $timeout): void
    {
        if ($this->callbackId === null) {
            $callbacks = &$this->callbacks;
            $timeoutCache = $this->timeoutCache;
            $this->callbackId = EventLoop::unreference(
                EventLoop::repeat(1, static function () use (&$callbacks, $timeoutCache): void {
                    $now = \time();

                    while ($id = $timeoutCache->extract($now)) {
                        \assert(isset($callbacks[$id]), "Timeout cache contains an invalid client ID");

                        // Client is either idle or taking too long to send request, so simply close the connection.
                        [$client, $streamId, $onTimeout] = $callbacks[$id];

                        $onTimeout($client, $streamId);
                    }
                })
            );
        }

        $cacheId = $this->makeId($client, $streamId);
        \assert(!isset($this->callbacks[$cacheId]));

        $this->callbacks[$cacheId] = [$client, $streamId, $onTimeout];
        $this->timeoutCache->update($cacheId, \time() + $timeout);
    }

    private function makeId(Client $client, int $streamId): string
    {
        return $client->getId() . ':' . $streamId;
    }

    /**
     * Update the timeout for the associated client and stream ID.
     */
    public function update(Client $client, int $streamId, int $timeout): void
    {
        $cacheId = $this->makeId($client, $streamId);
        \assert(isset($this->callbacks[$cacheId]));

        $this->timeoutCache->update($this->makeId($client, $streamId), \time() + $timeout);
    }

    /**
     * Remove the given stream ID.
     */
    public function remove(Client $client, int $streamId): void
    {
        $cacheId = $this->makeId($client, $streamId);

        $this->timeoutCache->clear($cacheId);
        unset($this->callbacks[$cacheId]);

        if (!$this->callbacks && $this->callbackId !== null) {
            EventLoop::cancel($this->callbackId);
            $this->callbackId = null;
        }
    }
}
