<?php

namespace Aerys\Handlers\Websocket;

class FrameWriter {
    
    private $destination;
    private $framePriorityQueue;
    private $currentFrame;
    private $buffer;
    private $bufferSize;
    private $frameBytesWritten = 0;
    
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
        if ($this->currentFrame) {
            $completedFrame = $this->doWrite();
        } elseif ($this->framePriorityQueue->count()) {
            $this->currentFrame = $this->framePriorityQueue->extract();
            $this->buffer = $this->currentFrame->__toString();
            $this->bufferSize = strlen($this->buffer);
            $completedFrame = $this->doWrite();
        } else {
            $completedFrame = NULL;
        }
        
        return $completedFrame;
    }
    
    private function doWrite() {
        $bytesWritten = @fwrite($this->destination, $this->buffer);
        $this->frameBytesWritten += $bytesWritten;
        
        if ($bytesWritten === $this->bufferSize) {
            $completedFrame = $this->currentFrame;
            $this->buffer = NULL;
            $this->bufferSize = NULL;
            $this->currentFrame = NULL;
            $this->frameBytesWritten = 0;
        } elseif ($bytesWritten) {
            $completedFrame = NULL;
            $this->buffer = substr($this->buffer, $bytesWritten);
            $this->bufferSize -= $bytesWritten;
        } elseif (!is_resource($this->destination)) {
            throw new FrameWriteException(
                $this->currentFrame,
                $this->frameBytesWritten,
                'Failed writing to destination'
            );
        } else {
            $completedFrame = NULL;
        }
        
        return $completedFrame;
    }
    
}

