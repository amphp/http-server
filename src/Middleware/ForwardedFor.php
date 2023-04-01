<?php

namespace Amp\Http\Server\Middleware;

use Amp\Socket\InternetAddress;

final class ForwardedFor
{
    /**
     * @param array<non-empty-string, string|null> $metadata
     */
    public function __construct(
        private readonly InternetAddress $address,
        private readonly array $metadata,
    ) {

    }

    public function getAddress(): InternetAddress
    {
        return $this->address;
    }

    public function getFieldValue(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }
}
