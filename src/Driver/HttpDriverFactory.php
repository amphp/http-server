<?php

namespace Amp\Http\Server\Driver;

use Amp\Socket\SocketServer;

interface HttpDriverFactory
{
    public function createHttpDriver(Client $client): HttpDriver;

    public function setUpSocketServer(SocketServer $server): SocketServer;
}
