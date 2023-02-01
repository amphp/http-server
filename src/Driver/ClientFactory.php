<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Http\Client\SocketException;
use Amp\Socket\Socket;

interface ClientFactory
{
    /**
     * Create a client object for the given Socket, enabling TLS if necessary or configuring other socket options.
     *
     * @throws SocketException
     */
    public function createClient(Socket $socket): ?Client;
}
