<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\DeferredCancellation;
use Amp\DeferredFuture;
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

    /** @var int Current body size limit. */
    public int $bodySizeLimit;

    /** @var int Bytes received on the stream. */
    public int $receivedByteCount = 0;

    public int $serverWindow;

    public int $clientWindow;

    public ?Future $pendingResponse = null;

    public ?Future $pendingWrite = null;

    public string $buffer = "";

    public int $state;

    public ?DeferredFuture $deferredFuture = null;

    /** @var int Integer between 1 and 256 */
    public int $weight = 0;

    public int $dependency = 0;

    public ?int $expectedLength = null;

    public readonly DeferredCancellation $deferredCancellation;

    public function __construct(int $bodySizeLimit, int $serverSize, int $clientSize, int $state = self::OPEN)
    {
        $this->bodySizeLimit = $bodySizeLimit;
        $this->serverWindow = $serverSize;
        $this->clientWindow = $clientSize;
        $this->state = $state;

        $this->deferredCancellation = new DeferredCancellation();
    }
}
