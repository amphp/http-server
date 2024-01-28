<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

enum Http3Settings: int
{
    case QPACK_MAX_TABLE_CAPACITY = 0x01;
    case MAX_FIELD_SECTION_SIZE = 0x06;
    case QPACK_BLOCKED_STREAMS = 0x07;
    case ENABLE_CONNECT_PROTOCOL = 0x08;
    case H3_DATAGRAM = 0x33;
}
