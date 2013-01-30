<?php

namespace Aerys\Http;

abstract class Message {
    
    private $protocol;
    private $headers = [];
    private $body;
    
    public function getProtocol() {
        return $this->protocol;
    }
    
    public function setProtocol($protocol) {
        $this->protocol = $protocol;
    }
    
    public function getHeaders() {
        return $this->headers;
    }
    
    public function setHeaders($headers) {
        $this->headers = $headers;
    }
    
    public function getBody() {
        return $this->body;
    }
    
    public function setBody($body) {
        $this->body = $body;
    }
    
    public function getStartLineAndHeaders() {
        $msg = $this->getStartLine() . "\r\n";
        
        foreach ($this->headers as $header => $value) {
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    $msg .= "$header: $nestedValue\r\n";
                }
            } else {
                $msg .= "$header: $value\r\n";
            }
        }
        
        $msg .= "\r\n";
        
        return $msg;
    }
    
    abstract public function getStartLine();
    
    abstract public function allowsEntityBody();
}
