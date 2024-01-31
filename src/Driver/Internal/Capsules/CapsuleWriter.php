<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Capsules;

interface CapsuleWriter
{
    public function write(int $type, string $buf);
}
