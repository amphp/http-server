<?php

namespace Aerys\Ws\Io;

use Aerys\Ws\Frame;

class FrameWriter {
    
    const START = 0;
    const WRITING = 1;
    
    private $state = self::START;
    private $destination;
    private $frameQueue;
    private $currentFrame;
    private $buffer;
    private $bufferSize;
    private $granularity = 8192;
    
    function __construct($destination) {
        $this->destination = $destination;
        $this->frameQueue = new FrameQueue;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function canWrite() {
        return ($this->currentFrame || $this->frameQueue->count());
    }
    
    function enqueue(Frame $frame) {
        $isControlFrame = ($frame->getOpcode() >= Frame::OP_CLOSE);
        $this->frameQueue->insert($frame, $isControlFrame);
    }
    
    function write() {
        if ($this->state == self::START && $this->frameQueue->count()) {
            goto start;
        } elseif ($this->state == self::WRITING) {
            goto writing;
        } else {
            return NULL;
        }
        
        start: {
            $this->currentFrame = $this->frameQueue->extract();
            $this->buffer = $this->currentFrame->__toString();
            $this->bufferSize = strlen($this->buffer);
            $this->state = self::WRITING;
            
            goto writing;
        }
        
        writing: {
            $byteWriteLimit = ($this->bufferSize > $this->granularity)
                ? $this->granularity
                : $this->bufferSize;
            
            $bytesWritten = @fwrite($this->destination, $this->buffer, $byteWriteLimit);
            
            if ($bytesWritten === $this->bufferSize) {
                goto frame_complete;
            } elseif ($bytesWritten) {
                $this->buffer = substr($this->buffer, $bytesWritten);
                $this->bufferSize -= $bytesWritten;
                goto further_write_needed;
            } elseif (is_resource($this->destination)) {
                goto further_write_needed;
            } else {
                throw new ResourceException(
                    'Failed writing to destination'
                );
            }
        }
        
        frame_complete: {
            $frame = $this->currentFrame;
            
            $this->buffer = NULL;
            $this->bufferSize = NULL;
            $this->currentFrame = NULL;
            $this->state = self::START;
            
            return $frame;
        }
        
        further_write_needed: {
            return NULL;
        }
        
    }
    
}

