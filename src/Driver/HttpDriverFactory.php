<?php

namespace Amp\Http\Server\Driver;

interface HttpDriverFactory
{
    public function createHttpDriver(Client $client): HttpDriver;
}
