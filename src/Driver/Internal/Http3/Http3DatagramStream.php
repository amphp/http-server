<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

use Amp\Cancellation;
use Amp\Quic\DatagramStream;
use Amp\Quic\QuicSocket;

class Http3DatagramStream implements DatagramStream
{
    public function __construct(private \Closure $reader, private \Closure $writer, private \Closure $maxSize, private QuicSocket $stream)
    {
    }

    public function send(string $data, ?Cancellation $cancellation = null): void
    {
        ($this->writer)($this->stream, $data, $cancellation);
    }

    public function receive(?Cancellation $cancellation = null): ?string
    {
        return ($this->reader)($this->stream, $cancellation);
    }

    public function maxDatagramSize(): int
    {
        return ($this->maxSize)();
    }
}
