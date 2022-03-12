<?php

namespace Amp\Http\Server\Driver;

interface TimeoutQueue
{
    /**
     * Insert or update the timeout for the associated client and stream ID.
     */
    public function update(string $streamId, Client $client, int $timeout): void;

    /**
     * Remove the given stream ID.
     */
    public function remove(string $streamId): void;
}
