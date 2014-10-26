<?php

namespace Aerys\Websocket;

/**
 * Standard Websocket close codes
 *
 * Notes:
 *
 *  - 1004: reserved by RFC 6455 (do not send)
 *  - 1005: is ONLY for logging when no code was received (do not send)
 *  - 1006: is ONLY for logging after an ungraceful disconnect (do not send)
 */
class Codes {
    const MIN = 1000;
    const MAX = 4999;
    const NORMAL_CLOSE = 1000;
    const GOING_AWAY = 1001;
    const PROTOCOL_ERROR = 1002;
    const UNACCEPTABLE_TYPE = 1003;
    const NONE = 1005;
    const ABNORMAL_CLOSE = 1006;
    const INCONSISTENT_FRAME_DATA_TYPE = 1007;
    const POLICY_VIOLATION = 1008;
    const MESSAGE_TOO_LARGE = 1009;
    const EXPECTED_EXTENSION_MISSING = 1010;
    const UNEXPECTED_SERVER_ERROR = 1011;
    const TLS_HANDSHAKE_FAILURE = 1015;
}
