<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\Payload;

/**
 * This class allows streamed and buffered access to an `InputStream` like `Amp\ByteStream\Payload`.
 *
 * Additionally, this class allows increasing the body size limit dynamically and allows access to the request trailers.
 */
final class RequestBody extends Payload
{
    /** @var callable|null */
    private $upgradeSize;

    /**
     * @param InputStream   $stream
     * @param callable|null $upgradeSize Callback used to increase the maximum size of the body.
     */
    public function __construct(InputStream $stream, callable $upgradeSize = null)
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
