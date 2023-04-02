<?php

namespace Amp\Http\Server\Middleware;

use Amp\Socket\InternetAddress;

final class ForwardedFor
{
    /**
     * @param array<non-empty-string, string|null> $fields
     */
    public function __construct(
        private readonly InternetAddress $address,
        private readonly array $fields,
    ) {

    }

    public function getAddress(): InternetAddress
    {
        return $this->address;
    }

    public function getFieldValue(string $key): ?string
    {
        return $this->fields[$key] ?? null;
    }
}
