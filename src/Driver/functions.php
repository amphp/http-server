<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

function createClientId(): int
{
    static $nextId = 0;

    return $nextId++;
}
