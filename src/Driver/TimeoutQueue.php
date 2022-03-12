<?php

namespace Amp\Http\Server\Driver;

interface TimeoutQueue
{
    /**
     * Insert a client and stream pair. The given closure is invoked when the timeout elapses.
     *
     * @param \Closure(Client, int):void $onTimeout
     */
    public function insert(Client $client, int $streamId, \Closure $onTimeout, int $timeout): void;

    /**
     * Update the timeout for the associated client and stream ID.
     */
    public function update(Client $client, int $streamId, int $timeout): void;

    /**
     * Remove the given stream ID.
     */
    public function remove(Client $client, int $streamId): void;
}
