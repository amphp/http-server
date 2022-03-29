<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Client\SocketException;
use Amp\Socket\EncryptableSocket;

interface ClientFactory
{
    /**
     * Create a client object for the given Socket, enabling TLS if necessary or configuring other socket options.
     *
     * @throws SocketException
     */
    public function createClient(EncryptableSocket $socket): ?Client;
}
