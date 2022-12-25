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
}
