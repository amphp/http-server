<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Server\Driver\Client;
use Amp\Sync\PriorityQueue;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\weakClosure;

/** @internal */
final class TimeoutQueue
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly PriorityQueue $priorityQueue;

    private readonly \WeakMap $streamNames;

    private readonly string $callbackId;

    private int $now;

    /** @var array<string, array{Client, int, \Closure(Client, int):void}> */
    private array $callbacks = [];

    public function __construct()
    {
        $this->priorityQueue = new PriorityQueue();
        $this->streamNames = new \WeakMap();
        $this->now = \time();

        $this->callbackId = EventLoop::unreference(
            EventLoop::repeat(1, weakClosure(function (): void {
                $this->now = \time();

                while ($id = $this->priorityQueue->extract($this->now)) {
                    \assert(isset($this->callbacks[$id]), "Timeout cache contains an invalid client ID");

                    // Client is either idle or taking too long to send request, so simply close the connection.
                    [$client, $streamId, $onTimeout] = $this->callbacks[$id];

                    async($onTimeout, $client, $streamId)->ignore();
                }
            }))
        );
    }

    public function __destruct()
    {
        EventLoop::cancel($this->callbackId);
    }

    /**
     * Insert a client and stream pair. The given closure is invoked when the timeout elapses.
     *
     * @param \Closure(Client, int):void $onTimeout
     */
    public function insert(Client $client, int $streamId, \Closure $onTimeout, int $timeout): void
    {
        $cacheId = $this->makeId($client, $streamId);
        \assert(!isset($this->callbacks[$cacheId]));

        $this->callbacks[$cacheId] = [$client, $streamId, $onTimeout];
        $this->priorityQueue->insert($cacheId, $this->now + $timeout);
    }

    private function makeId(Client $client, int $streamId): string
    {
        /** @psalm-suppress InaccessibleProperty $streamNames is a WeakMap */
        $streamMap = $this->streamNames[$client] ??= new \ArrayObject();
        return $streamMap[$streamId] ??= $client->getId() . ':' . $streamId;
    }

    /**
     * Update the timeout for the associated client and stream ID.
     */
    public function update(Client $client, int $streamId, int $timeout): void
    {
        $cacheId = $this->makeId($client, $streamId);
        \assert(isset($this->callbacks[$cacheId], $this->streamNames[$client][$streamId]));

        $this->priorityQueue->insert($cacheId, $this->now + $timeout);
    }

    public function suspend(Client $client, int $streamId): void
    {
        $cacheId = $this->makeId($client, $streamId);
        \assert(isset($this->callbacks[$cacheId], $this->streamNames[$client][$streamId]));

        $this->priorityQueue->remove($cacheId);
    }

    /**
     * Remove the given stream ID.
     */
    public function remove(Client $client, int $streamId): void
    {
        $cacheId = $this->makeId($client, $streamId);

        $this->priorityQueue->remove($cacheId);
        unset($this->callbacks[$cacheId], $this->streamNames[$client][$streamId]);
    }
}
