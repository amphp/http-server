<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

enum Http3Error: int
{
    case QPACK_DECOMPRESSION_FAILED = 0x200;
    case QPACK_ENCODER_STREAM_ERROR = 0x201;
    case QPACK_DECODER_STREAM_ERROR = 0x202;
}
