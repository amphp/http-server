<?php

namespace Amp\Http\Server\Middleware;

use Amp\Socket\InternetAddress;

final class ForwardedFor
{
    public function __construct(
        private readonly InternetAddress $for,
        private readonly ?InternetAddress $by = null,
        private readonly ?string $host = null,
        private readonly ?string $proto = null,
    ) {
    }

    public function getFor(): InternetAddress
    {
        return $this->for;
    }

    public function getBy(): ?InternetAddress
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
