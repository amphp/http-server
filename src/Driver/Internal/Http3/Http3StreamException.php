<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

use Amp\Quic\QuicSocket;

final class Http3StreamException extends \Exception
{
    public function __construct(
        string $message,
        private readonly QuicSocket $stream,
        Http3Error $code,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code->value, $previous);
    }

    public function getStream(): QuicSocket
    {
        return $this->stream;
    }

    public function releaseStream(): void
    {
        unset($this->stream);
    }
}
