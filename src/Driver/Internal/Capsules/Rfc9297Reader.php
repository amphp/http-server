<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Capsules;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Driver\Internal\Http3\Http3Parser;

final class Rfc9297Reader implements CapsuleReader
{
    private string $buf = "";
    private bool $activeReader = false;

    public function __construct(private readonly ReadableStream $stream)
    {
    }

    public function read(): ?array
    {
        if ($this->activeReader) {
            throw new PendingReadError;
        }
        $this->activeReader = true;

        $off = 0;
        $type = Http3Parser::decodeVarintFromStream($this->stream, $this->buf, $off);
        $length = Http3Parser::decodeVarintFromStream($this->stream, $this->buf, $off);
        if ($length === -1) {
            return null;
        }
        $this->buf = \substr($this->buf, $off);

        $reader = function () use ($length) {
            while (\strlen($this->buf) < $length) {
                yield $this->buf;
                $length -= \strlen($this->buf);

                if (null === $buf = $this->stream->read()) {
                    return;
                }
                $this->buf = $buf;
            }
            yield \substr($this->buf, 0, $length);
            $this->buf = \substr($this->buf, $length);
            $this->activeReader = false;
        };
        return [$type, $length, $reader()];
    }
}
