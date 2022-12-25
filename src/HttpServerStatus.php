<?php declare(strict_types=1);

namespace Amp\Http\Server;

enum HttpServerStatus
{
    case Starting;
    case Started;
    case Stopping;
    case Stopped;

    public function getLabel(): string
    {
        return match ($this) {
            self::Starting => 'Starting',
            self::Started => 'Started',
            self::Stopping => 'Stopping',
            self::Stopped => 'Stopped',
        };
    }
}
