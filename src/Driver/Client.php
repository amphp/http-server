<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Closable;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

interface Client extends Closable
{
    /**
     * Integer ID of this client.
     */
    public function getId(): int;

    /**
     * @inheritDoc
     * @param int $close An optional close reason for streams which support a close reason.
     */
    public function close(int $reason = 0): void;

    /**
     * @return SocketAddress Remote client address.
     */
    public function getRemoteAddress(): SocketAddress;

    /**
     * @return SocketAddress Local server address.
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * If the client is encrypted a TlsInfo object is returned, otherwise null.
     */
    public function getTlsInfo(): ?TlsInfo;

    /**
     * @return bool Whether the client uses QUIC.
     */
    public function isQuicClient(): bool;
}
