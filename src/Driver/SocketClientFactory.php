<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;

final class SocketClientFactory implements ClientFactory
{
    private float $tlsHandshakeTimeout = 5;

    public function createClient(Socket $socket): ?Client
    {
        $context = \stream_context_get_options($socket->getResource());
        if ($socket instanceof EncryptableSocket && isset($context["ssl"])) {
            $socket->setupTls(new TimeoutCancellation($this->tlsHandshakeTimeout));
            $tlsInfo = $socket->getTlsInfo();
        }

        return new SocketClient($socket, $tlsInfo ?? null);
    }
}
