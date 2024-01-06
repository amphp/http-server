<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\Driver\Client;
use Amp\Quic\QuicConnection;
use Amp\Socket\Socket;

abstract class StreamHttpDriver extends ConnectionHttpDriver
{
    public function handleConnection(Client $client, QuicConnection|Socket $connection): void
    {
        $this->handleClient($client, $connection, $connection);
    }
}
