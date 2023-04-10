<?php

namespace Amp\Http\Server\Middleware;

final class Forwarded
{
    public function __construct(
        private readonly string $for,
        private readonly ?string $by = null,
        private readonly ?string $host = null,
        private readonly ?string $proto = null,
    ) {
    }

    public function getFor(): string
    {
        return $this->for;
    }

    public function getBy(): ?string
    {
        return $this->by;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getProto(): ?string
    {
        return $this->proto;
    }
}
