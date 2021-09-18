<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\Deferred;
use Amp\Future;

/**
 * Used in Http2Driver.
 *
 * @internal
 */
final class Http2Stream
{
    public const OPEN = 0;
    public const RESERVED = 0b0001;
    public const REMOTE_CLOSED = 0b0010;
    public const LOCAL_CLOSED = 0b0100;
    public const CLOSED = 0b0110;

    /** @var int Current max body length. */
    public int $maxBodySize;

    /** @var int Bytes received on the stream. */
    public int $received = 0;

    public int $serverWindow;

    public int $clientWindow;

    public ?Future $pendingResponse = null;

    public ?Future $pendingWrite = null;

    public string $buffer = "";

    public int $state;

    public ?Deferred $deferred = null;

    /** @var int Integer between 1 and 256 */
    public int $weight = 0;

    public int $dependency = 0;

    public ?int $expectedLength = null;

    public function __construct(int $serverSize, int $clientSize, int $state = self::OPEN)
    {
        $this->serverWindow = $serverSize;
        $this->maxBodySize = $serverSize;
        $this->clientWindow = $clientSize;
        $this->state = $state;
    }
}
