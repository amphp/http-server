<?php

namespace Aerys\Ws;

class Codes {
    
    const NORMAL_CLOSE = 1000;
    const GOING_AWAY = 1001;
    const PROTOCOL_ERROR = 1002;
    const UNACCEPTABLE_TYPE = 1003;
    
    //1004 -- RESERVED BY RFC6455
    
    /**
     * MUST not be sent by endpoints -- 1005 is ONLY for logging after the fact when no code rcvd
     */
    const NONE = 1005;
    
    /**
     * MUST not be sent by endpoints -- 1006 is ONLY for logging after the fact when no code rcvd
     */
    const ABNORMAL_CLOSE = 1006;
    
    const INCONSISTENT_FRAME_DATA_TYPE = 1007;
    const POLICY_VIOLATION = 1008;
    const MESSAGE_TOO_LARGE = 1009;
    const EXPECTED_EXTENSION_MISSING = 1010;
    const UNEXPECTED_SERVER_ERROR = 1011;
    const TLS_HANDSHAKE_FAILURE = 1015;
    
}

