<?php

namespace Aerys\Ws;

class Frame {
    
    const FIN      = 0b1;
    const MORE     = 0b0;
    const RSV_NONE = 0b000;
    
    const OP_CONT  = 0x00;
    const OP_TEXT  = 0x01;
    const OP_BIN   = 0x02;
    const OP_CLOSE = 0x08;
    const OP_PING  = 0x09;
    const OP_PONG  = 0x0A;
    
    private $fin;
    private $rsv;
    private $opcode;
    private $length;
    private $payload;
    private $maskingKey;
    
    function __construct($fin, $rsv, $opcode, $payload, $maskingKey = NULL) {
        $this->fin = $fin;
        $this->rsv = $rsv;
        $this->opcode = $opcode;
        $this->length = strlen($payload);
        $this->payload = $payload;
        $this->maskingKey = isset($maskingKey) ? $maskingKey : NULL;
    }
    
    function isFin() {
        return $this->fin;
    }

    function isRsv1() {
        return (bool) $this->rsv & 0b001;
    }

    function isRsv2() {
        return (bool) $this->rsv & 0b010;
    }

    function isRsv3() {
        return (bool) $this->rsv & 0b100;
    }
    
    function getOpcode() {
        return $this->opcode;
    }
    
    function getMaskingKey() {
        return $this->maskingKey;
    }
    
    function getLength() {
        return $this->length;
    }
    
    function getPayload() {
        return $this->payload;
    }
    
    function __toString() {
        if ($this->length > 0xFFFF) {
            $lengthHeader = 0x7F;
            // This limits payload to 2.1GB
            $lengthBody = "\x00\x00\x00\x00" . pack('N', $this->length);
        } elseif ($this->length > 0x7D) {
            $lengthHeader = 0x7E;
            $lengthBody = pack('n', $this->length);
        } else {
            $lengthHeader = $this->length;
            $lengthBody = '';
        }

        $firstByte = 0x00;
        $firstByte |= ((int) $this->fin) << 7;
        $firstByte |= $this->rsv << 4;
        $firstByte |= $this->opcode;

        $secondByte = 0x00;
        $secondByte |= ((int) isset($this->maskingKey)) << 7;
        $secondByte |= $lengthHeader;

        $firstWord = chr($firstByte) . chr($secondByte);

        if ($this->maskingKey || $this->maskingKey === '0') {
            $maskingKey = $this->maskingKey;
            $payload = $this->payload
                ? $this->payload ^ str_pad('', $this->length, $maskingKey, STR_PAD_RIGHT)
                : '';
        } else {
            $maskingKey = '';
            $payload = $this->payload;
        }

        return $firstWord . $lengthBody . $maskingKey . $payload;
    }
    
}

