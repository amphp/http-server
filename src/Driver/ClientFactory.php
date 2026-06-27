<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Socket\Socket;
use Amp\Socket\SocketException;

interface ClientFactory
{
    /**
     * Create a client object for the given Socket, enabling TLS if necessary or configuring other socket options.
     *
     * @throws SocketException
     */
    public function createClient(Socket $socket): ?Client;
}
