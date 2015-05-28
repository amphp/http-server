<?php

namespace Aerys\Websocket;

class Code {
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
};
