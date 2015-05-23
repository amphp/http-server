<?php

namespace Aerys\Websocket;

const CODES = [
    "NORMAL_CLOSE" => 1000,
    "GOING_AWAY" => 1001,
    "PROTOCOL_ERROR" => 1002,
    "UNACCEPTABLE_TYPE" => 1003,
    "NONE" => 1005,
    "ABNORMAL_CLOSE" => 1006,
    "INCONSISTENT_FRAME_DATA_TYPE" => 1007,
    "POLICY_VIOLATION" => 1008,
    "MESSAGE_TOO_LARGE" => 1009,
    "EXPECTED_EXTENSION_MISSING" => 1010,
    "UNEXPECTED_SERVER_ERROR" => 1011,
    "TLS_HANDSHAKE_FAILURE" => 1015,
];

const FRAME = [
    "OP_CONT"  => 0x00,
    "OP_TEXT"  => 0x01,
    "OP_BIN"   => 0x02,
    "OP_CLOSE" => 0x08,
    "OP_PING"  => 0x09,
    "OP_PONG"  => 0x0A,
];