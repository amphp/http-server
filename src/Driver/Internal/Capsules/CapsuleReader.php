<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Capsules;

interface CapsuleReader
{
    /**
     * Will read the next capsule. Note that no Cancellable is accepted. To cancel reading, close the underlying stream.
     * @psalm-return list{int, int, iterable}|null type, length, then content
     */
    public function read(): ?array;
}
