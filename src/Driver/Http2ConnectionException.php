<?php

namespace Amp\Http\Server\Driver;

final class Http2ConnectionException extends Http2Exception
{
    public function __construct(Client $client, string $message, int $code, ?\Throwable $previous = null)
    {
        parent::__construct($client, $message, $code, $previous);
    }
}
