<?php

namespace Aerys\Handlers\Websocket;

class Message {
    
    private $opcode;
    private $payload;
    private $bufferedPayload;
    private $streamifiedPayload;
    private $length;
    private $frames;
    private $isStream;
    
    function __construct($opcode, $payload, $length, array $frames) {
        $this->opcode = $opcode;
        $this->payload = $payload;
        $this->length = $length;
        $this->frames = $frames;
        
        $this->isStream = is_resource($payload);
    }
    
    function getPayload() {
        return $this->isStream ? $this->getBufferedPayload() : $this->payload;
    }
    
    private function getBufferedPayload() {
        if (isset($this->bufferedPayload)) {
            return $this->bufferedPayload;
        }
        
        $startPos = ftell($this->payload);
        rewind($this->payload);
        if (FALSE === ($this->bufferedPayload = stream_get_contents($this->payload))) {
            throw new \RuntimeException(
                'Failed buffering payload data from stream resource'
            );
        }
        
        fseek($this->payload, $startPos);
        
        return $this->bufferedPayload;
    }
    
    function getPayloadStream() {
        return $this->isStream ? $this->payload : $this->getStreamifiedPayload();
    }
    
    private function getStreamifiedPayload() {
        if (isset($this->streamifiedPayload)) {
            return $this->streamifiedPayload;
        } else {
            $uri = 'data://text/plain;base64,' . base64_encode($this->payload);
            return $this->streamifiedPayload = fopen($uri, 'r');
        }
    }
    
    function getType() {
        return $this->opcode;
    }
    
    function getLength() {
        return $this->length;
    }
    
    function getFrames() {
        return $this->frames;
    }
    
    function isStream() {
        return $this->isStream;
    }
    
}

