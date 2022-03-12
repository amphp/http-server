<?php

namespace Amp\Http\Server\Driver;

use Revolt\EventLoop;

final class DefaultTimeoutQueue implements TimeoutQueue
{
    private readonly TimeoutCache $timeoutCache;

    private string $callbackId;

    /** @var array<string, Client> */
    private array $clients = [];

    public function __construct()
    {
        $this->timeoutCache = $timeoutCache = new TimeoutCache();

        $clients = &$this->clients;
        $this->callbackId = EventLoop::unreference(
            EventLoop::repeat(1, static function () use (&$clients, $timeoutCache): void {
                $now = \time();

                while ($id = $timeoutCache->extract($now)) {
                    \assert(isset($clients[$id]), "Timeout cache contains an invalid client ID");

                    // Client is either idle or taking too long to send request, so simply close the connection.
                    $clients[$id]->close();
                }
            })
        );
    }

    public function __destruct()
    {
        EventLoop::cancel($this->callbackId);
    }

    public function update(string $streamId, Client $client, int $timeout): void
    {
        $this->clients[$streamId] = $client;
        $this->timeoutCache->update($streamId, \time() + $timeout);
    }

    public function remove(string $streamId): void
    {
        unset($this->clients[$streamId]);
        $this->timeoutCache->clear($streamId);
    }
}
