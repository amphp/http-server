<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\Socket;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

final class SocketClientFactory implements ClientFactory
{
    /** @var Client[] */
    private array $clients = [];

    private TimeoutCache $timeoutCache;

    private string $timeoutId;

    public function __construct()
    {
        $this->timeoutCache = new TimeoutCache;
        $this->timeoutId = EventLoop::disable(EventLoop::repeat(1, $this->checkClientTimeouts(...)));
    }

    public function __destruct()
    {
        EventLoop::cancel($this->timeoutId);
    }

    public function createClient(Socket $socket): ?Client
    {
        $context = \stream_context_get_options($this->socket->getResource());
        if (isset($context["ssl"])) {
            $this->setupTls();
        }

        $client = new SocketClient($socket, $this->timeoutCache);

        if (!$this->clients) {
            EventLoop::enable($this->timeoutId);
        }

        $this->clients[$client->getId()] = $client;
        $client->onClose($this->handleClose(...));

        return $client;
    }

    /**
     * Called by start() after the client connects if encryption is enabled.
     */
    private function setupTls(): void
    {
        $this->timeoutCache->update(
            $this->id,
            \time() + $this->options->getTlsSetupTimeout()
        );

        $this->socket->setupTls(new TimeoutCancellation($this->options->getTlsSetupTimeout()));

        $this->tlsInfo = $this->socket->getTlsInfo();

        \assert($this->tlsInfo !== null);
        \assert($this->logDebug("TLS handshake complete with {address} ({tls.version}, {tls.cipher}, {tls.alpn})", [
            $this->socket->getRemoteAddress(),
            $this->tlsInfo->getVersion(),
            $this->tlsInfo->getCipherName(),
            $this->tlsInfo->getApplicationLayerProtocol() ?? "none",
        ]));
    }

    private function handleClose(Client $client): void
    {
        unset($this->clients[$client->getId()]);
        if (!$this->clients) {
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
