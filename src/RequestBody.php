<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\Payload;
use Amp\Promise;
use Amp\Success;

/**
 * This class allows streamed and buffered access to an `InputStream` like `Amp\ByteStream\Payload`.
 *
 * Additionally, this class allows increasing the body size limit dynamically and allows access to the request trailers.
 */
final class RequestBody extends Payload {
    /** @var callable|null */
    private $upgradeSize;

    /** @var Promise */
    private $trailers;

    /**
     * @param \Amp\ByteStream\InputStream $stream
     * @param callable|null               $upgradeSize Callback used to increase the maximum size of the body.
     * @param Promise|null                $trailers Promise for trailing headers.
     */
    public function __construct(InputStream $stream, callable $upgradeSize = null, Promise $trailers = null) {
        parent::__construct($stream);
        $this->upgradeSize = $upgradeSize;
        $this->trailers = $trailers ?? new Success(new Trailers([]));
    }

    /**
     * Set a new maximum length of the body in bytes.
     *
     * @param int $size
     */
    public function increaseSizeLimit(int $size) {
        if (!$this->upgradeSize) {
            return;
        }

        ($this->upgradeSize)($size);
    }

    /**
     * @return Promise<\Amp\Http\Server\Trailers>
     */
    public function getTrailers(): Promise {
        return $this->trailers;
    }
}
