<?php

namespace Amp\Http\Server\Middleware;

enum ForwardedForHeaderType
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
