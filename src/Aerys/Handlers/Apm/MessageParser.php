<?php

namespace Aerys\Handlers\Apm;

class MessageParser {
    
    const HEADERS = 0;
    const BODY = 1;
    
    private $state = self::HEADERS;
    private $buffer = '';
    private $bufferSize = 0;
    
    private $version;
    private $type;
    private $requestId;
    private $length;
    private $body;
    
    private $onMessage;
    
    function setOnMessageCallback(callable $onMessage) {
        $this->onMessage = $onMessage;
        return $this;
    }
    
    function parse($data) {
        $this->buffer .= $data;
        $this->bufferSize += strlen($data);
        
        if ($this->state == self::HEADERS) {
            goto headers;
        } else {
            goto body;
        }
        
        headers: {
            if ($this->bufferSize < Message::HEADER_SIZE) {
                goto more_data_needed;
            }
            
            $this->headers();
            
            if (!$this->length) {
                goto complete;
            } elseif ($this->bufferSize >= $this->length) {
                goto body;
            } else {
                $this->state = self::BODY;
                goto more_data_needed;
            }
        }
        
        body: {
            if ($this->body()) {
                goto complete;
            } else {
                goto more_data_needed;
            }
        }
        
        complete: {
            $msg = [$this->type, $this->requestId, $this->body];
            
            $this->state = self::HEADERS;
            $this->version = NULL;
            $this->type = NULL;
            $this->requestId = NULL;
            $this->length = NULL;
            $this->body = NULL;
            
            if (!$onMessage = $this->onMessage) {
                return $msg;
            }
            
            $onMessage($msg);
            
            if ($this->bufferSize) {
                goto headers;
            } else {
                return;
            }
        }
        
        more_data_needed: {
            return;
        }
    }
    
    private function headers() {
        $header = substr($this->buffer, 0, Message::HEADER_SIZE);
        $data = unpack(Message::HEADER_UNPACK_PATTERN, $header);
        
        $this->version = $data['version'];
        $this->type = $data['type'];
        $this->requestId = $data['requestId'];
        $this->length = $data['length'];
        
        $this->buffer = (string) substr($this->buffer, Message::HEADER_SIZE);
        $this->bufferSize -= Message::HEADER_SIZE;
    }
    
    private function body() {
        if ($this->bufferSize >= $this->length) {
            $this->body = substr($this->buffer, 0, $this->length);
            $this->buffer = (string) substr($this->buffer, $this->length);
            $this->bufferSize -= $this->length;
            
            return TRUE;
            
        } else {
        
            return FALSE;
            
        }
    }
    
}

