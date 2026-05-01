<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\Driver\Client;
use function Amp\async;
use function Amp\weakClosure;

/** @internal */
final class StreamTimeoutTracker
{
    private readonly \Closure $onStreamTimeout;

    /** @var array<int, \Closure(int): void> */
    private array $callbacks = [];

    /** @psalm-suppress UnusedProperty False-positive. */
    private int $pingTimeout = 0;

    /**
     * @param \Closure():void $onConnectionTimeout
     */
    public function __construct(
        private readonly Client $client,
        private readonly TimeoutQueue $timeoutQueue,
        private readonly int $connectionTimeout,
        private readonly int $streamTimeout,
        \Closure $onConnectionTimeout,
    ) {
        $this->onStreamTimeout = weakClosure(function (Client $client, int $streamId): void {
            \assert(isset($this->callbacks[$streamId]), "Callback missing for stream ID " . $streamId);

            $callback = $this->callbacks[$streamId];
            unset($this->callbacks[$streamId]);

            async($callback, $streamId)->ignore();

            if (!$this->callbacks) {
                $this->timeoutQueue->update($this->client, 0, \min(0, $this->pingTimeout - \time()));
            }
        });

        $timeoutQueue->insert($this->client, 0, $onConnectionTimeout, $this->connectionTimeout);
    }

    public function __destruct()
    {
        $this->timeoutQueue->remove($this->client, 0);
    }

    public function ping(int $timeout): void
    {
        $this->pingTimeout = \time() + $timeout;

        if (!$this->callbacks) {
            $this->timeoutQueue->update($this->client, 0, $timeout);
        }
    }

    public function insert(int $streamId, \Closure $onTimeout): void
    {
        \assert($streamId > 0 && $streamId & 1);

        $this->timeoutQueue->insert($this->client, $streamId, $this->onStreamTimeout, $this->streamTimeout);
        $this->callbacks[$streamId] = $onTimeout;
        $this->timeoutQueue->suspend($this->client, 0);
    }

    public function update(int $streamId): void
    {
        \assert($streamId > 0 && $streamId & 1);

        $this->timeoutQueue->update($this->client, $streamId, $this->streamTimeout);
    }

    public function suspend(int $streamId): void
    {
        \assert($streamId > 0 && $streamId & 1);

        $this->timeoutQueue->suspend($this->client, $streamId);
    }

    public function remove(int $streamId): void
    {
        \assert($streamId > 0 && $streamId & 1);

        $this->timeoutQueue->remove($this->client, $streamId);
        unset($this->callbacks[$streamId]);

        if (!$this->callbacks) {
            $this->timeoutQueue->update($this->client, 0, $this->connectionTimeout);
        }
    }
}
