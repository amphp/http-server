<?php

namespace Amp\Http\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\Payload;

/**
 * This class allows streamed and buffered access to an `ReadableStream` like `Amp\ByteStream\Payload`.
 *
 * Additionally, this class allows increasing the body size limit dynamically and allows access to the request trailers.
 */
final class RequestBody extends Payload
{
    /** @var callable|null */
    private $upgradeSize;

    /**
     * @param ReadableStream   $stream
     * @param null|Closure(int):void $upgradeSize Closure used to increase the maximum size of the body.
     */
    public function __construct(ReadableStream $stream, ?\Closure $upgradeSize = null)
    {
        parent::__construct($stream);
        $this->upgradeSize = $upgradeSize;
    }

    /**
     * Set a new maximum length of the body in bytes.
     *
     * @param int $size
     */
    public function increaseSizeLimit(int $size): void
    {
        if (!$this->upgradeSize) {
            return;
        }

        ($this->upgradeSize)($size);
    }
}
