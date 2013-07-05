<?php

namespace Aerys\Handlers\Websocket;

class FrameWriter {
    
    private $destination;
    private $framePriorityQueue;
    private $currentFrame;
    private $buffer;
    private $bufferSize;
    
    function __construct($destination) {
        $this->destination = $destination;
        $this->framePriorityQueue = new FramePriorityQueue;
    }
    
    function canWrite() {
        return ($this->currentFrame || $this->framePriorityQueue->count());
    }
    
    function enqueue(Frame $frame) {
        $this->framePriorityQueue->insert($frame);
    }
    
    function write() {
        if (!($this->currentFrame || $this->framePriorityQueue->count())) {
            return;
        } elseif (!$this->currentFrame) {
            $this->currentFrame = $this->framePriorityQueue->extract();
            $this->buffer = $this->currentFrame->__toString();
            $this->bufferSize = strlen($this->buffer);
        }
        
        return $this->doWrite();
    }
    
    private function doWrite() {
        $bytesWritten = @fwrite($this->destination, $this->buffer);
        $completedFrame = NULL;
        
        if ($bytesWritten === $this->bufferSize) {
            $completedFrame = $this->currentFrame;
            $this->buffer = NULL;
            $this->bufferSize = NULL;
            $this->currentFrame = NULL;
        } elseif ($bytesWritten) {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $this->bufferSize -= $bytesWritten;
        } elseif (!is_resource($this->destination)) {
            throw new ResourceException(
                'Failed writing to destination'
            );
        }
        
        return $completedFrame;
    }
    
}

