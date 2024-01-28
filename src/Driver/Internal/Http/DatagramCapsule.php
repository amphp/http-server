<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http;

use Amp\Cancellation;
use Amp\Quic\DatagramStream;
use Amp\Socket\PendingReceiveError;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

class DatagramCapsule implements CapsuleReader, DatagramStream
{
    const TYPE = 0;
    private ?Suspension $datagramSuspension = null;
    private ?Cancellation $cancellation;
    private ?string $cancellationId = null;
    private ?string $nextDatagram = null;

    public function __construct(private CapsuleReader $reader, private CapsuleWriter $writer)
    {
    }

    public function read(): ?array
    {
        while ($data = $this->reader->read()) {
            [$type, $len, $content] = $data;
            if ($type === self::TYPE) {
                if ($len > 65535) {
                    // Too big, we don't want that
                    return null;
                }

                $buf = \implode(\iterator_to_array($content));
                if (\strlen($buf) < $len) {
                    return null;
                }

                if ($this->datagramSuspension) {
                    $this->datagramSuspension->resume($buf);
                    $this->datagramSuspension = null;
                    $this->cancellation?->unsubscribe($this->cancellationId);
                    $this->cancellation = null;
                } else {
                    $this->nextDatagram = $buf;

                    // We need to await a tick to allow datagram receivers to request a new datagram to avoid needlessly discarding datagram frames
                    $suspension = EventLoop::getSuspension();
                    EventLoop::queue($suspension->resume(...));
                    $suspension->suspend();
                }
                continue;
            }
            return $data;
        }
        return null;
    }

    public function send(string $data, ?Cancellation $cancellation = null): void
    {
        $this->writer->write(self::TYPE, $data);
    }

    public function receive(?Cancellation $cancellation = null): ?string
    {
        if ($this->nextDatagram !== null) {
            $data = $this->nextDatagram;
            $this->nextDatagram = null;
            return $data;
        }

        if ($this->datagramSuspension) {
            throw new PendingReceiveError;
        }

        $this->datagramSuspension = EventLoop::getSuspension();
        $this->cancellation = $cancellation;
        $this->cancellationId = $cancellation?->subscribe(function ($e) {
            $this->datagramSuspension->throw($e);
            $this->datagramSuspension = null;
        });
        return $this->datagramSuspension->suspend();
    }

    public function maxDatagramSize(): int
    {
        return 1192; // 8 bytes overhead plus ... what MTU to assume - QUIC also has overhead itself?
    }
}
