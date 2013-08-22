<?php

namespace Aerys\Responders\Websocket;

abstract class FrameStream implements \Countable, \SeekableIterator {
    
    private $opcode;
    protected $frameSize = 65536;
    
    final function __construct($opcode, $dataSource) {
        if ($opcode === Frame::OP_TEXT || $opcode === Frame::OP_BIN) {
            $this->opcode = $opcode;
        } else {
            throw new \DomainException(
                'Invalid opcode specified at '.__CLASS__.'::'.__METHOD__.' Argument 1'
            );
        }
        
        $this->setDataSource($dataSource);
    }
    
    abstract protected function setDataSource($dataSource);
    
    function getOpcode() {
        return $this->opcode;
    }
    
    function isText() {
        return ($this->opcode === Frame::OP_TEXT);
    }
    
    function isBinary() {
        return ($this->opcode === Frame::OP_BIN);
    }
    
    function setFrameSize($bytes) {
        $bytes = (int) $bytes;
        $this->frameSize = ($bytes < 0) ? 0 : $bytes;
    }
    
    function getFrameSize() {
        return $this->frameSize;
    }
    
}

