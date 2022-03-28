<?php

namespace Amp\Http\Server\Driver;

use Amp\CancelledException;
use Amp\Socket\EncryptableSocket;
use Amp\TimeoutCancellation;

final class SocketClientFactory implements ClientFactory
{
    public function __construct(
        private readonly float $tlsHandshakeTimeout = 5,
    ) {
    }

    public function createClient(EncryptableSocket $socket): ?Client
    {
        if ($socket->isTlsAvailable()) {
            try {
                $socket->setupTls(new TimeoutCancellation($this->tlsHandshakeTimeout));
            } catch (CancelledException) {
                return null;
            }
        }

        return new SocketClient($socket);
    }
}
