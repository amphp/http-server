<?php

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;

final class UpgradedSocket implements Socket
{
    private string $readBuffer = '';

    public function __construct(
        private Client $client,
        private ReadableStream $readableStream,
        private WritableStream $writableStream,
    ) {
    }

    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string
    {
        if ($this->readBuffer !== '') {
            $buffer = $this->readBuffer;
            $this->readBuffer = '';

            if ($limit !== null && \strlen($buffer) > $limit) {
                $this->readBuffer = \substr($buffer, $limit);
                return \substr($buffer, 0, $limit);
            }

            return $buffer;
        }

        $buffer = $this->readableStream->read($cancellation);
        if ($buffer === null) {
            return null;
        }

        if ($limit !== null && \strlen($buffer) > $limit) {
            $this->readBuffer = \substr($buffer, $limit);
            return \substr($buffer, 0, $limit);
        }

        return $buffer;
    }

    public function close(): void
    {
        $this->readableStream->close();
        $this->writableStream->close();
        $this->client->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function write(string $bytes): void
    {
        $this->writableStream->write($bytes);
    }

    public function end(): void
    {
        $this->writableStream->end();
    }

    public function reference(): void
    {
        if ($this->writableStream instanceof ResourceStream) {
            $this->writableStream->reference();
        }
    }

    public function unreference(): void
    {
        if ($this->writableStream instanceof ResourceStream) {
            $this->writableStream->unreference();
        }
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->client->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->client->getRemoteAddress();
    }

    public function isClosed(): bool
    {
        return !$this->isReadable() && !$this->isWritable();
    }

    public function isReadable(): bool
    {
        return $this->readBuffer !== '' || $this->readableStream->isReadable();
    }

    public function isWritable(): bool
    {
        return $this->writableStream->isWritable();
    }

    public function getResource() {
        if (!($this->writableStream instanceof ResourceStream)) {
            throw new \Exception("Cannot get resource from non-socket stream");
        }
        return $this->writableStream->getResource();
    }
}
