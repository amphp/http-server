<?php

namespace Amp\Http\Server\Driver;

function createClientId(): int
{
    static $nextId = 0;

    return $nextId++;
}
