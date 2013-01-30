<?php

namespace Aerys\Http;

class Response extends Message {
    
    private $status;
    private $reason;
    
    public function setAll($protocol, $status, $reason, $headers, $body) {
        $this->status = $status;
        $this->reason = $reason;
        
        $this->setProtocol($protocol);
        $this->setHeaders($headers);
        $this->setBody($body);
        
        return $this;
    }
    
    public function getStatus() {
        return $this->status;
    }
    
    public function setStatus($status) {
        $this->status = $status;
    }
    
    public function getReason() {
        return $this->reason;
    }
    
    public function setReason($reason) {
        $this->reason = $reason;
    }
    
    final public function getStartLine() {
        $startLine = "HTTP/" . $this->getProtocol() . " " . $this->status;
        if ($this->reason || $this->reason === '0') {
            $startLine .= " " . $this->reason;
        }
        
        return $startLine;
    }
    
    final public function allowsEntityBody() {
        return !($this->status == 204 || $this->status == 304 || $this->status < 200);
    }
}
