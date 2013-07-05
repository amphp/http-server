<?php

namespace Aerys\Handlers\Websocket;

class Message {
    
    private $opcode;
    private $payload;
    private $bufferedPayload;
    private $streamifiedPayload;
    private $length;
    private $isStream;
    
    function __construct($opcode, $payload, $length) {
        $this->opcode = $opcode;
        $this->payload = $payload;
        $this->length = $length;
        
        $this->isStream = is_resource($payload);
    }
    
    function isStream() {
        return $this->isStream;
    }
    
    function getType() {
        return $this->opcode;
    }
    
    function getLength() {
        return $this->length;
    }
    
    function getPayload() {
        return $this->isStream ? $this->bufferPayload() : $this->payload;
    }
    
    private function bufferPayload() {
        if (isset($this->bufferedPayload)) {
            return $this->bufferedPayload;
        }
        
        $startPos = ftell($this->payload);
        if (!@rewind($this->payload)) {
            throw new \RuntimeException(
                'Failed seeking stream resource'
            );
        }
        
        if (FALSE === ($this->bufferedPayload = @stream_get_contents($this->payload))) {
            throw new \RuntimeException(
                'Failed buffering stream resource'
            );
        }
        
        if (@fseek($this->payload, $startPos)) {
            throw new \RuntimeException(
                'Failed seeking stream resource'
            );
        }
        
        return $this->bufferedPayload;
    }
    
    function getPayloadStream() {
        return $this->isStream ? $this->payload : $this->streamifyPayload();
    }
    
    private function streamifyPayload() {
        if (isset($this->streamifiedPayload)) {
            return $this->streamifiedPayload;
        } else {
            $uri = 'data://text/plain;base64,' . base64_encode($this->payload);
            return $this->streamifiedPayload = fopen($uri, 'r');
        }
    }
    
}

