<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Http\Server\RequestBody;

class UnbufferedBodyStream implements ReadableStream
{
    private int $dataRead = 0;

    public function __construct(private RequestBody $body)
    {
    }

    public function close(): void
    {
        $this->body->close();
    }

    public function isClosed(): bool
    {
        return $this->body->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->body->onClose($onClose);
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        $this->body->increaseSizeLimit($this->dataRead + 1);
        $read = $this->body->read($cancellation);
        if ($read === null) {
            return null;
        }
        $this->dataRead += \strlen($read);
        $this->body->increaseSizeLimit($this->dataRead);
        return $read;
    }

    public function isReadable(): bool
    {
        return $this->body->isReadable();
    }
}
