<?php

namespace Amp\Http\Server\Driver;

interface TimeoutQueue
{
    public function addStream(Client $client, string $streamId, int $timeout): void;

    public function removeStream(string $streamId): void;

    public function update(string $streamId, int $timeout): void;
}
