<?php

namespace Amp\Http\Server\Driver;

use Amp\CancellationToken;
use Amp\Future;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

final class UpgradedSocket implements EncryptableSocket
{
    private ?string $buffer;

    /**
     * @param Client $client
     * @param Socket $socket
     * @param string $buffer Remaining buffer previously read from the socket.
     */
    public function __construct(
        private Client $client,
        private Socket $socket,
        string $buffer
    ) {
        $this->client = $client;
        $this->socket = $socket;
        $this->buffer = $buffer !== '' ? $buffer : null;
    }

    public function read(): ?string
    {
        if ($this->buffer !== null) {
            $buffer = $this->buffer;
            $this->buffer = null;
            return $buffer;
        }

        return $this->socket->read();
    }

    public function close(): void
    {
        $this->socket->close();
        $this->client->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function write(string $data): Future
    {
        return $this->socket->write($data);
    }

    public function end(string $finalData = ""): Future
    {
        return $this->socket->end($finalData);
    }

    public function reference(): void
    {
        $this->socket->reference();
    }

    public function unreference(): void
    {
        $this->socket->unreference();
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    public function getResource()
    {
        return $this->socket->getResource();
    }

    public function setupTls(?CancellationToken $token = null): void
    {
        $this->socket->setupTls($token);
    }

    public function shutdownTls(?CancellationToken $token = null): void
    {
        $this->socket->shutdownTls();
    }

    public function getTlsState(): int
    {
        return $this->socket->getTlsState();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->socket->getTlsInfo();
    }
}
