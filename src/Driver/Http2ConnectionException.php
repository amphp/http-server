<?php

namespace Amp\Http\Server\Driver;

final class Http2ConnectionException extends Http2Exception
{
    public function __construct(string $message, int $code, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
