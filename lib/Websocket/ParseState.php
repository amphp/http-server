<?php

namespace Aerys\Websocket;

class ParseState extends \Amp\Struct {
    const PARSE_FRAME = 1;
    const PARSE_ERR_SYNTAX = 2;
    const PARSE_ERR_POLICY = 3;

    const START = 0;
    const LENGTH_126 = 1;
    const LENGTH_127 = 2;
    const MASKING_KEY = 3;
    const CONTROL_PAYLOAD = 4;
    const PAYLOAD = 5;

    public $state = self::START;
    public $buffer;
    public $bufferSize = 0;
    public $frameLength;
    public $frameBytesRecd = 0;
    public $dataMsgBytesRecd = 0;
    public $dataPayload;
    public $controlPayload;
    public $isControlFrame;

    public $fin;
    public $rsv;
    public $opcode;
    public $isMasked;
    public $maskingKey;
}
