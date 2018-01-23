<?php

namespace Aerys;

use Amp\ByteStream\InputStream;
use Amp\Coroutine;
use Amp\Promise;
use function Amp\call;

/**
 * This class allows streamed and buffered access to an `InputStream` similar to `Amp\ByteStream\Message`.
 *
 * `Amp\ByteStream\Message` is not extended due to it implementing `Amp\Promise`, which makes resolving promises with it
 * impossible. `Amp\ByteStream\Message` will probably be adjusted to follow this implementation in the future.
 */
class Body implements InputStream {
    /** @var InputStream */
    private $stream;

    /** @var \Amp\Promise|null */
    private $promise;

    /** @var \Amp\Promise|null */
    private $lastRead;

    /** @var callable|null */
    private $upgradeSize;

    /**
     * @param \Amp\ByteStream\InputStream $stream
     * @param callable|null $upgradeSize Callback used to increase the maximum size of the body.
     */
    public function __construct(InputStream $stream, callable $upgradeSize = null) {
        $this->stream = $stream;
        $this->upgradeSize = $upgradeSize;
    }

    public function __destruct() {
        if (!$this->promise) {
            Promise\rethrow(new Coroutine($this->consume()));
        }
    }

    private function consume(): \Generator {
        try {
            if ($this->lastRead && null === yield $this->lastRead) {
                return;
            }

            while (null !== yield $this->stream->read()) {
                // Discard unread bytes from message.
            }
        } catch (\Throwable $exception) {
            // If exception is thrown here the connection closed anyway.
        }
    }

    /**
     * @inheritdoc
     *
     * @throws \Error If a buffered message was requested by calling buffer().
     */
    public function read(): Promise {
        if ($this->promise) {
            throw new \Error("Cannot stream message data once a buffered message has been requested");
        }

        return $this->lastRead = $this->stream->read();
    }

    /**
     * Buffers the entire message and resolves the returned promise then.
     *
     * @return Promise<string> Resolves with the entire message contents.
     */
    public function buffer(): Promise {
        if ($this->promise) {
            return $this->promise;
        }

        return $this->promise = call(function () {
            $buffer = '';
            if ($this->lastRead && null === yield $this->lastRead) {
                return $buffer;
            }

            while (null !== $chunk = yield $this->stream->read()) {
                $buffer .= $chunk;
            }
            return $buffer;
        });
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
}
