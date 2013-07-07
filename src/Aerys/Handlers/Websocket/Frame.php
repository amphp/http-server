<?php

namespace Aerys\Handlers\Websocket;

/**
 * An immutable value object modeling websocket frames according to RFC 6455 Section 5
 * 
 * @link http://tools.ietf.org/html/rfc6455#section-5
 * 
 * @TODO Support > 32-bit Frame sizes (very low-priority)
 * 
 * Though the websocket protocol allows for up to 64-bit payload lengths we only support 
 * a maximum of 32-bit lengths at this time. This is sensible because PHP's string type is
 * limited to 32-bit sizes anyway and Aerys buffers Frame payloads as strings. We could expand
 * this to support stream resources and/or Countable Iterators. This change would necessarily
 * require the creation of a separate method (Frame::buffer()) to replace __toString() because
 * __toString() is not allowed to throw exceptions. Streams/Iterators would necessarily need
 * to be capable of throwing if something went wrong during the buffering of their respective
 * content.
 */
class Frame {
    
    const FIN      = 0b1;
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
        $this->length = strlen($payload); // Limits payloads to 2.1GB
        $this->payload = $payload;
        $this->maskingKey = isset($maskingKey) ? $maskingKey : NULL;
    }
    
    function isFin() {
        return (bool) $this->fin;
    }

    function hasRsv1() {
        return (bool) ($this->rsv & 0b001);
    }

    function hasRsv2() {
        return (bool) ($this->rsv & 0b010);
    }

    function hasRsv3() {
        return (bool) ($this->rsv & 0b100);
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
            $lengthBody = "\x00\x00\x00\x00" . pack('N', $this->length); // Limits payloads to 2.1GB
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

        if ($maskingKey = $this->maskingKey) {
            $payload = ($this->payload || $this->payload === '0')
                ? $this->payload ^ str_pad('', $this->length, $maskingKey, STR_PAD_RIGHT)
                : '';
        } else {
            $payload = $this->payload;
        }

        return $firstWord . $lengthBody . $maskingKey . $payload;
    }
    
}

