<?php

namespace Amp\Http\Server\Middleware;

enum ForwardedForHeaderType
{
    case FORWARDED;
    case X_FORWARDED_FOR;
}
