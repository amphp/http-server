<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsException;
use Amp\Socket\TlsInfo;
use Amp\Socket\TlsState;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class UpgradedSocket implements Socket, ResourceStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private string $readBuffer = '';

    public function __construct(
        private readonly Client $client,
        private readonly ReadableStream $readableStream,
        private readonly WritableStream $writableStream,
    ) {
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    #[\Override]
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

    #[\Override]
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

    #[\Override]
    public function write(string $bytes): void
    {
        $this->writableStream->write($bytes);
    }

    #[\Override]
    public function end(): void
    {
        $this->writableStream->end();
    }

    #[\Override]
    public function reference(): void
    {
        if ($this->writableStream instanceof ResourceStream) {
            $this->writableStream->reference();
        }
    }

    #[\Override]
    public function unreference(): void
    {
        if ($this->writableStream instanceof ResourceStream) {
            $this->writableStream->unreference();
        }
    }

    #[\Override]
    public function getLocalAddress(): SocketAddress
    {
        return $this->client->getLocalAddress();
    }

    #[\Override]
    public function getRemoteAddress(): SocketAddress
    {
        return $this->client->getRemoteAddress();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return !$this->isReadable() && !$this->isWritable();
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
        $this->readableStream->onClose($onClose);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->readBuffer !== '' || $this->readableStream->isReadable();
    }

    #[\Override]
    public function isWritable(): bool
    {
        return $this->writableStream->isWritable();
    }

    #[\Override]
    public function getResource()
    {
        return $this->writableStream instanceof ResourceStream
            ? $this->writableStream->getResource()
            : null;
    }

    #[\Override]
    public function setupTls(?Cancellation $cancellation = null): never
    {
        throw new TlsException('Not implemented on upgraded sockets; TLS should already be enabled if available');
    }

    #[\Override]
    public function shutdownTls(?Cancellation $cancellation = null): never
    {
        throw new TlsException('Not implemented on upgraded sockets');
    }

    #[\Override]
    public function isTlsConfigurationAvailable(): bool
    {
        return $this->writableStream instanceof Socket
            ? $this->writableStream->isTlsConfigurationAvailable()
            : false;
    }

    #[\Override]
    public function getTlsState(): TlsState
    {
        return $this->writableStream instanceof Socket
            ? $this->writableStream->getTlsState()
            : TlsState::Disabled;
    }

    #[\Override]
    public function getTlsInfo(): ?TlsInfo
    {
        return $this->writableStream instanceof Socket
            ? $this->writableStream->getTlsInfo()
            : null;
    }
}
