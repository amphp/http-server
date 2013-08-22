<?php

namespace Aerys\Responders\Websocket;

class FrameWriteException extends \RuntimeException {
    
    private $bytesCompleted;
    private $frame;
    
    function __construct(Frame $frame, $bytesCompleted, $msg, $code = 0, $previousException = NULL) {
        $this->frame = $frame;
        $this->bytesCompleted = $bytesCompleted;
        parent::__construct($msg, $code, $previousException);
    }
    
    function getFrame() {
        return $this->frame;
    }
    
    function getBytesCompleted() {
        return $this->bytesCompleted;
    }
    
}
