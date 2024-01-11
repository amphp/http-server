<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

enum Http3Error: int
{
    case H3_NO_ERROR = 0x100;
    case H3_GENERAL_PROTOCOL_ERROR = 0x101;
    case H3_INTERNAL_ERROR = 0x102;
    case H3_STREAM_CREATION_ERROR = 0x103;
    case H3_CLOSED_CRITICAL_STREAM = 0x104;
    case H3_FRAME_UNEXPECTED = 0x105;
    case H3_FRAME_ERROR = 0x106;
    case H3_EXCESSIVE_LOAD = 0x107;
    case H3_ID_ERROR = 0x108;
    case H3_SETTINGS_ERROR = 0x109;
    case H3_MISSING_SETTINGS = 0x10a;
    case H3_REQUEST_REJECTED = 0x10b;
    case H3_REQUEST_CANCELLED = 0x10c;
    case H3_REQUEST_INCOMPLETE = 0x10d;
    case H3_MESSAGE_ERROR = 0x10e;
    case H3_CONNECT_ERROR = 0x10f;
    case H3_VERSION_FALLBACK = 0x110;
    case QPACK_DECOMPRESSION_FAILED = 0x200;
    case QPACK_ENCODER_STREAM_ERROR = 0x201;
    case QPACK_DECODER_STREAM_ERROR = 0x202;
}
