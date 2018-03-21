<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\Struct;

/**
 * Used in Http2Driver.
 *
 * @internal
 */
final class Http2Stream {
    use Struct;

    const OPEN = 0;
    const RESERVED = 0b0001;
    const REMOTE_CLOSED = 0b0010;
    const LOCAL_CLOSED = 0b0100;
    const CLOSED = 0b0110;

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

    /** @var \Amp\Promise|null */
    public $pendingResponse;

    /** @var \Amp\Promise|null */
    public $pendingWrite;

    /** @var string */
    public $buffer = "";

    /** @var int */
    public $state;

    /** @var \Amp\Deferred|null */
    public $deferred;

    public function __construct(int $serverSize, int $clientSize, int $state = self::OPEN) {
        $this->serverWindow = $serverSize;
        $this->maxBodySize = $serverSize;
        $this->clientWindow = $clientSize;
        $this->state = $state;
    }
}
