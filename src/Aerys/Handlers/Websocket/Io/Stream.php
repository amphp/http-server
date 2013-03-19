<?php

namespace Aerys\Handlers\Websocket\Io;

use Aerys\Handlers\Websocket\Frame;

abstract class Stream implements \Countable, \Iterator {
    
    private $payloadType;
    protected $autoFrameSize = 32768;
    
    function __construct($payloadType) {
        if ($payloadType == Frame::OP_TEXT || $payloadType == Frame::OP_BIN) {
            $this->payloadType = $payloadType;
        } else {
            throw new \DomainException(
                'Invalid payload type'
            );
        }
    }
    
    function setAutoFrameSize($bytes) {
        $this->autoFrameSize = (int) $bytes;
    }
    
    function getPayloadType() {
        return $this->payloadType;
    }
    
    function isBinary() {
        return ($this->payloadType === Frame::OP_BIN);
    }
    
}

