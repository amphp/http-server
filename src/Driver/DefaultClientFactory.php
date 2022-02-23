<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\Socket;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;

final class DefaultClientFactory implements ClientFactory
{
    private TimeoutCache $timeoutCache;

    private string $timeoutId;

    private int $clientCount = 0;

    public function __construct()
    {
        $this->timeoutCache = new TimeoutCache;
        $this->timeoutId = EventLoop::disable(EventLoop::repeat(1, $this->checkClientTimeouts(...)));
    }

    public function __destruct()
    {
        EventLoop::cancel($this->timeoutId);
    }

    public function createClient(
        Socket $socket,
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        Options $options,
    ): Client {
        $client = new RemoteClient($socket, $requestHandler, $errorHandler, $logger, $options, $this->timeoutCache);

        if ($this->clientCount === 0) {
            EventLoop::enable($this->timeoutId);
        }

        $this->clientCount++;
        $client->onClose($this->handleClose(...));

        return $client;
    }

    private function handleClose(): void
    {
        $this->clientCount--;
        if ($this->clientCount === 0) {
            EventLoop::disable($this->timeoutId);
        }
    }

    private function checkClientTimeouts(): void
    {
        $now = \time();

        while ($id = $this->timeoutCache->extract($now)) {
            \assert(isset($this->clients[$id]), "Timeout cache contains an invalid client ID");

            $client = $this->clients[$id];

            if ($client->isWaitingOnResponse()) {
                $this->timeoutCache->update($id, $now + 1);
                continue;
            }

            // Client is either idle or taking too long to send request, so simply close the connection.
            $client->close();
        }
    }
}
