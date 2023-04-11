<?php declare(strict_types=1);

namespace Amp\Http\Server\Middleware;

enum ForwardedHeaderType
{
    case Forwarded;
    case XForwardedFor;

    public function getHeaderName(): string
    {
        return match ($this) {
            self::Forwarded => 'forwarded',
            self::XForwardedFor => 'x-forwarded-for',
        };
    }
}
