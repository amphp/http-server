<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\Payload;
use Amp\Promise;
use Amp\Success;

/**
 * This class allows streamed and buffered access to an `InputStream` similar to `Amp\ByteStream\Message`.
 *
 * `Amp\ByteStream\Message` is not extended due to it implementing `Amp\Promise`, which makes resolving promises with it
 * impossible. `Amp\ByteStream\Message` will probably be adjusted to follow this implementation in the future.
 */
final class Body extends Payload implements InputStream {
    /** @var callable|null */
    private $upgradeSize;

    /** @var \Amp\Promise */
    private $trailers;

    /**
     * @param \Amp\ByteStream\InputStream $stream
     * @param callable|null $upgradeSize Callback used to increase the maximum size of the body.
     * @param \Amp\Promise|null $trailers Promise for array of trailing headers.
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
    public function increaseMaxSize(int $size) {
        if (!$this->upgradeSize) {
            return;
        }

        ($this->upgradeSize)($size);
    }

    /**
     * @return \Amp\Promise<\Amp\Http\Server\Trailers>
     */
    public function getTrailers(): Promise {
        return $this->trailers;
    }
}
