<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\Driver\Client;
use Amp\Quic\QuicConnection;
use Amp\Socket\Socket;

abstract class StreamHttpDriver extends ConnectionHttpDriver
{
    public function handleConnection(Client $client, QuicConnection|Socket $connection): void
    {
        assert($connection instanceof Socket);
        $this->handleClient($client, $connection, $connection);
    }
}
