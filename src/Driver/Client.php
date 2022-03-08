<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

interface Client
{
    /**
     * Integer ID of this client.
     */
    public function getId(): int;

    /**
     * @return SocketAddress Remote client address.
     */
    public function getRemoteAddress(): SocketAddress;

    /**
     * @return SocketAddress Local server address.
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * @return bool `true` if the client is connected via a unix socket
     */
    public function isUnix(): bool;

    /**
     * @return bool `true` if the client is encrypted, `false` if plaintext.
     */
    public function isEncrypted(): bool;

    /**
     * If the client is encrypted a TlsInfo object is returned, otherwise null.
     */
    public function getTlsInfo(): ?TlsInfo;

    /**
     * Attaches a callback invoked with this client closes. The callback is passed this object as the first parameter.
     *
     * @param \Closure(Client):void $onClose
     */
    public function onClose(\Closure $onClose): void;

    /**
     * Forcefully closes the client connection.
     */
    public function close(): void;

    /**
     * @return bool {@code true} if the connection has been closed, {@code false} otherwise.
     */
    public function isClosed(): bool;
}
