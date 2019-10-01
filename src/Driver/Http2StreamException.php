<?php

namespace Amp\Http\Server\Driver;

final class Http2StreamException extends Http2Exception
{
    /** @var int */
    private $streamId;

    public function __construct(Client $client, string $message, int $streamId, int $code, ?\Throwable $previous = null)
    {
        parent::__construct($client, $message, $code, $previous);
        $this->streamId = $streamId;
    }

    public function getStreamId(): int
    {
        return $this->streamId;
    }
}
