<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

enum Http3StreamType: int
{
    case Control = 0x0;
    case Push = 0x1;
    case QPackEncode = 0x2;
    case QPackDecode = 0x3;
}
