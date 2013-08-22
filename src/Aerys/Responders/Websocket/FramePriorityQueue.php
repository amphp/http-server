<?php

namespace Aerys\Responders\Websocket;

class FramePriorityQueue implements \Countable {
    
    private $priorityQueue;
    private $serial = PHP_INT_MAX;
    
    final function __construct() {
        $this->priorityQueue = new \SplPriorityQueue;
    }
    
    function insert(Frame $frame) {
        $priority = ($frame->getOpcode() >= Frame::OP_CLOSE);
        $this->priorityQueue->insert($frame, [$priority, $this->serial--]);
    }
    
    function extract() {
        return $this->priorityQueue->extract();
    }
    
    function count() {
        return $this->priorityQueue->count();
    }
    
}

