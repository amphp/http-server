<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\Deferred;
use Amp\Http\Server\Driver\Priority;
use Amp\Promise;
use Amp\Struct;

/**
 * Used in Http2Driver.
 *
 * @internal
 */
final class Http2Stream
{
    use Struct;

    public const OPEN = 0;
    public const RESERVED = 0b0001;
    public const REMOTE_CLOSED = 0b0010;
    public const LOCAL_CLOSED = 0b0100;
    public const CLOSED = 0b0110;

    /** @var string|null Packed header string. */
    public $headers;

    /** @var int Current max body length. */
    public $maxBodySize;

    /** @var int Bytes received on the stream. */
    public $received = 0;

    /** @var int */
    public $serverWindow;

    /** @var int */
    public $clientWindow;

    /** @var Promise|null */
    public $pendingResponse;

    /** @var Promise|null */
    public $pendingWrite;

    /** @var string */
    public $buffer = "";

    /** @var int */
    public $state;

    /** @var Deferred|null */
    public $deferred;

    /** @var Priority */
    public $priority;

    /** @var int|null */
    public $expectedLength;

    public function __construct(int $id, int $serverSize, int $clientSize, int $state = self::OPEN)
    {
        $this->priority = new Priority($id);
        $this->serverWindow = $serverSize;
        $this->maxBodySize = $serverSize;
        $this->clientWindow = $clientSize;
        $this->state = $state;
    }
}
