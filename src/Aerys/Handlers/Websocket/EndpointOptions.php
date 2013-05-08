<?php

namespace Aerys\Handlers\Websocket;

class EndpointOptions {
    
    private $beforeHandshake  = NULL;
    private $subprotocol      = NULL;
    private $allowedOrigins   = [];
    private $msgSwapSize      = 2097152;
    private $maxFrameSize     = 2097152;
    private $maxMsgSize       = 10485760;
    private $autoFrameSize    = 32768;
    private $queuedPingLimit  = 3;
    private $heartbeatPeriod  = 10;
    
    function __construct(array $options = []) {
        foreach ($options as $name => $value) {
            $method = 'set' . ucfirst($name);
            if (isset($value) && method_exists($this, $method)) {
                $this->$method($value);
            }
        }
    }
    
    function getBeforeHandshake() {
        return $this->beforeHandshake;
    }
    
    function getSubprotocol() {
        return $this->subprotocol;
    }
    
    function getAllowedOrigins() {
        return $this->allowedOrigins;
    }
    
    function getMsgSwapSize() {
        return $this->msgSwapSize;
    }
    
    function getMaxFrameSize() {
        return $this->maxFrameSize;
    }
    
    function getMaxMsgSize() {
        return $this->maxMsgSize;
    }
    
    function getAutoFrameSize() {
        return $this->autoFrameSize;
    }
    
    function getQueuedPingLimit() {
        return $this->queuedPingLimit;
    }
    
    function getHeartbeatPeriod() {
        return $this->heartbeatPeriod;
    }
    
    private function setBeforeHandshake(callable $beforeHandshake) {
        $this->beforeHandshake = $beforeHandshake;
    }
    
    private function setSubprotocol($subprotocol) {
        $this->subprotocol = $subprotocol;
    }
    
    private function setAllowedOrigins(array $origins) {
        $this->allowedOrigins = array_map('strtolower', $origins);
    }
    
    private function setMsgSwapSize($bytes) {
        $this->msgSwapSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 2097152,
            'min_range' => 0
        ]]);
    }
    
    private function setMaxFrameSize($bytes) {
        $this->maxFrameSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 2097152,
            'min_range' => 1
        ]]);
    }
    
    private function setMaxMsgSize($bytes) {
        $this->maxMsgSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 10485760,
            'min_range' => 1
        ]]);
    }
    
    private function setAutoFrameSize($bytes) {
        $this->autoFrameSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 32768,
            'min_range' => 0
        ]]);
    }
    
    private function setQueuedPingLimit($count) {
        $this->queuedPingLimit = filter_var($count, FILTER_VALIDATE_INT, ['options' => [
            'default' => 3,
            'min_range' => 1,
            'max_range' => 99
        ]]);
    }
    
    private function setHeartbeatPeriod($seconds) {
        $this->heartbeatPeriod = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'default' => 10,
            'min_range' => 0
        ]]);
    }
    
}

