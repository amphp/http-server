<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Revolt\EventLoop;

final class SocketClient implements Client
{
    private int $id;

    private ?TlsInfo $tlsInfo = null;

    /** @var \Closure[]|null */
    private ?array $onClose = [];

    public function __construct(private Socket $socket)
    {
        $this->id = createClientId();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    public function isUnix(): bool
    {
        return $this->getRemoteAddress()->getPort() === null;
    }

    public function isEncrypted(): bool
    {
        return $this->tlsInfo !== null;
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->tlsInfo;
    }

    public function close(): void
    {
        if ($this->onClose === null) {
            return; // Client already closed.
        }

        $onClose = $this->onClose;
        $this->onClose = null;

        $this->socket->close();

        foreach ($onClose as $closure) {
            EventLoop::queue(fn () => $closure($this));
        }
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->onClose === null) {
            EventLoop::queue(fn () => $onClose($this));
        } else {
            $this->onClose[] = $onClose;
        }
    }

    public function isClosed(): bool
    {
        return $this->onClose === null;
    }
}
