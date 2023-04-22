<?php declare(strict_types=1);

namespace Amp\Http\Server\Middleware;

use Amp\Socket\InternetAddress;

final class Forwarded
{
    /**
     * @param array<non-empty-string, string|null> $fields
     */
    public function __construct(
        private readonly InternetAddress $for,
        private readonly array $fields,
    ) {
    }

    public function getFor(): InternetAddress
    {
        return $this->for;
    }

    public function getField(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }
}
