<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

enum Http3Frame: int
{
    case DATA = 0x00;
    case HEADERS = 0x01;
    case CANCEL_PUSH = 0x03;
    case SETTINGS = 0x04;
    case PUSH_PROMISE = 0x05;
    case GOAWAY = 0x07;
    case ORIGIN = 0x0c;
    case MAX_PUSH_ID = 0x0d;
    case PRIORITY_UPDATE_Request = 0xF0700;
    case PRIORITY_UPDATE_Push = 0xF0701;
}
