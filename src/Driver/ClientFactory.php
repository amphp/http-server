<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\Socket;

interface ClientFactory
{
    public function createClient(Socket $socket): ?Client;
}
