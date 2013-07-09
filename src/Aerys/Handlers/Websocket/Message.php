<?php

namespace Aerys\Handlers\Websocket;

class Message {
    
    private $opcode;
    private $payload;
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
    
    function getOpcode() {
        return $this->opcode;
    }
    
    function getLength() {
        return $this->length;
    }
    
    function getPayload() {
        return $this->isStream ? $this->bufferPayload() : $this->payload;
    }
    
    private function bufferPayload() {
        $startPos = ftell($this->payload);
        if (!@rewind($this->payload)) {
            throw new \RuntimeException(
                'Failed seeking stream resource'
            );
        }
        
        $bufferedPayload = @stream_get_contents($this->payload);
        
        if ($bufferedPayload === FALSE) {
            throw new \RuntimeException(
                'Failed buffering stream resource'
            );
        }
        
        if (@fseek($this->payload, $startPos)) {
            throw new \RuntimeException(
                'Failed seeking stream resource'
            );
        }
        
        return $bufferedPayload;
    }
    
    function getPayloadStream() {
        if ($this->isStream) {
            $payloadStream = $this->payload;
        } else {
            $payloadStream = fopen('data://text/plain;base64,' . base64_encode($this->payload), 'r');
        }
        
        return $payloadStream;
    }
}

