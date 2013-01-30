<?php

namespace Aerys\Http;

class Request extends Message {
    
    private $method;
    private $uri;
    
    public function getMethod() {
        return $this->method;
    }
    
    public function setMethod($method) {
        $this->method = $method;
    }
    
    public function getUri() {
        return $this->uri;
    }
    
    public function setUri($uri) {
        $this->uri = $uri;
    }
    
    public function setAll($method, $uri, $protocol, $headers, $body) {
        $this->method = $method;
        $this->uri = $uri;
        
        $this->setProtocol($protocol);
        $this->setHeaders($headers);
        $this->setBody($body);
        
        return $this;
    }
    
    final public function getStartLine() {
        return $this->method . " " . $this->uri . " HTTP/" . $this->getProtocol();
    }
}
