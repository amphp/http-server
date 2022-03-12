<?php

namespace Amp\Http\Server\Driver;

use Revolt\EventLoop;

final class DefaultTimeoutQueue implements TimeoutQueue
{
    private readonly TimeoutCache $timeoutCache;

    private string $callbackId;

    /** @var array<string, array{}> */
    private array $callbacks = [];

    public function __construct()
    {
        $this->timeoutCache = $timeoutCache = new TimeoutCache();

        $callbacks = &$this->callbacks;
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

    public function __destruct()
    {
        EventLoop::cancel($this->callbackId);
    }

    public function insert(Client $client, int $streamId, \Closure $onTimeout, int $timeout): void
    {
        $cacheId = $this->makeId($client, $streamId);
        \assert(!isset($this->callbacks[$cacheId]));

        $this->callbacks[$cacheId] = [$client, $streamId, $onTimeout];
        $this->timeoutCache->update($cacheId, \time() + $timeout);
    }

    public function update(Client $client, int $streamId, int $timeout): void
    {
        $cacheId = $this->makeId($client, $streamId);
        \assert(isset($this->callbacks[$cacheId]));

        $this->timeoutCache->update($this->makeId($client, $streamId), \time() + $timeout);
    }

    public function remove(Client $client, int $streamId): void
    {
        $cacheId = $this->makeId($client, $streamId);

        $this->timeoutCache->clear($cacheId);
        unset($this->callbacks[$cacheId]);
    }

    private function makeId(Client $client, int $streamId): string
    {
        return $client->getId() . ':' . $streamId;
    }
}
