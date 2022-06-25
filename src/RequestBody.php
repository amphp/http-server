<?php

namespace Amp\Http\Server;

use Amp\ByteStream\BufferException;
use Amp\ByteStream\Payload;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;

/**
 * This class allows streamed and buffered access to a request body with an API similar to {@see Payload}.
 *
 * The {@see read()} and {@see buffer()} methods can also throw {@see ClientException} in addition to the usual
 * {@see StreamException}, though generally there is no need to catch this exception.
 *
 * Additionally, this class allows increasing the body size limit dynamically.
 */
final class RequestBody implements ReadableStream
{
    private readonly Payload $stream;

    /**
     * @param null|\Closure(int):void $upgradeSize Closure used to increase the maximum size of the body.
     */
    public function __construct(
        ReadableStream|string $stream,
        private readonly ?\Closure $upgradeSize = null,
    ) {
        $this->stream = new Payload($stream);
    }

    /**
     * @throws ClientException
     * @throws StreamException
     */
    public function read(?Cancellation $cancellation = null): ?string
    {
        return $this->stream->read($cancellation);
    }

    /**
     * @see Payload::buffer()
     * @throws ClientException
     * @throws BufferException|StreamException
     */
    public function buffer(?Cancellation $cancellation = null, int $limit = \PHP_INT_MAX): string
    {
        return $this->stream->buffer($cancellation, $limit);
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    /**
     * Indicates the remainder of the request body is no longer needed and will be discarded.
     */
    public function close(): void
    {
        $this->stream->close();
    }

    public function isClosed(): bool
    {
        return $this->stream->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->stream->onClose($onClose);
    }

    /**
     * Set a new maximum length of the body in bytes.
     */
    public function increaseSizeLimit(int $size): void
    {
        if ($this->upgradeSize === null) {
            return;
        }

        ($this->upgradeSize)($size);
    }
}
