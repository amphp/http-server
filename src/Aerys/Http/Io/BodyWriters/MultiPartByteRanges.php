<?php

namespace Aerys\Http\Io\BodyWriters;

use Aerys\Http\Io\ResourceException,
    Aerys\Http\Io\MultiPartByteRangeBody;

class MultiPartByteRanges extends BodyWriter {
    
    const BOUNDARY = 0;
    const RANGE = 1;
    const FINAL_BOUNDARY = 2;
    
    private $state = self::BOUNDARY;
    
    private $destination;
    private $body;
    private $contentType;
    
    private $boundary;
    private $boundarySize;
    private $boundaryBytesWritten;
    
    private $source;
    private $sourcePos;
    private $bytesRemainingInRange;
    
    function __construct($destination, MultiPartByteRangeBody $body) {
        $this->destination = $destination;
        $this->body = $body;
        $this->source = $body->getResource();
    }
    
    function write() {
        switch ($this->state) {
            case self::BOUNDARY: goto boundary;
            case self::RANGE: goto range;
            case self::FINAL_BOUNDARY: goto final_boundary;
        }
        
        boundary: {
            if ($this->writeBoundary()) {
                goto range_start;
            } else {
                goto further_write_needed;
            }
        }
        
        range_start: {
            list($startPos, $endPos) = $this->body->current();
            $this->body->next();
            
            $this->sourcePos = $startPos;
            
            // The additional `+1` is NOT an accident or a hack. Reported positions in the relevant
            // headers and multipart boundaries start at zero. Meanwhile, the source stream's 
            // resource position is numbered starting from one. The addition is required to output
            // the correct byte range for the specified start and end positions.
            $this->bytesRemainingInRange = $endPos - $startPos + 1;
            
            $this->state = self::RANGE;
            
            goto range;
        }
        
        range: {
            if ($this->writeRange()) {
                goto range_complete;
            } else {
                goto further_write_needed;
            }
        }
        
        range_complete: {
            if ($this->body->valid()) {
                $this->state = self::BOUNDARY;
                goto boundary;
            } else {
                $this->state = self::FINAL_BOUNDARY;
                
                $this->boundary = '--' . $this->body->getBoundary() . "--\r\n";
                $this->boundarySize = strlen($this->boundary);
                $this->boundaryBytesWritten = 0;
                
                goto final_boundary;
            }
        }
        
        final_boundary: {
            if ($this->writeBoundary()) {
                return TRUE;
            } else {
                goto further_write_needed;
            }
        }
        
        further_write_needed: {
            return FALSE;
        }
    }
    
    private function writeBoundary() {
        if (NULL === $this->boundary) {
            $this->generateBoundary();
        }
        
        $bytesWritten = @fwrite($this->destination, $this->boundary);
        
        $this->boundaryBytesWritten += $bytesWritten;
        
        if ($this->boundaryBytesWritten == $this->boundarySize) {
            $this->boundary = NULL;
            $this->boundarySize = NULL;
            $this->boundaryBytesWritten = NULL;
            
            return TRUE;
            
        } elseif ($bytesWritten) {
            $this->boundary = substr($this->boundary, $bytesWritten);
            return FALSE;
        } elseif (!is_resource($this->destination)) {
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
        }
    }
    
    private function generateBoundary() {
        list($startPos, $endPos) = $this->body->current();
        
        $boundary = '--' . $this->body->getBoundary() . "\r\n";
        $boundary.= 'Content-Type: ' . $this->body->getContentType() . "\r\n";
        $boundary.= "Content-Range: bytes {$startPos}-{$endPos}/" . $this->body->getContentLength();
        $boundary.= "\r\n\r\n";
        
        $this->boundary = $boundary;
        $this->boundarySize = strlen($boundary);
        $this->boundaryBytesWritten = 0;
    }
    
    private function writeRange() {
        $maxWriteSize = ($this->bytesRemainingInRange > $this->granularity)
            ? $this->granularity
            : $this->bytesRemainingInRange;
        
        $bytesWritten = @stream_copy_to_stream(
            $this->source,
            $this->destination,
            $maxWriteSize,
            $this->sourcePos
        );
        
        $this->sourcePos += $bytesWritten;
        $this->bytesRemainingInRange -= $bytesWritten;
        
        if (!$this->bytesRemainingInRange) {
            $this->bytesRemainingInRange = NULL;
            $this->sourcePos = NULL;
            
            return TRUE;
            
        } elseif ($bytesWritten) {
            return FALSE;
        } elseif (!is_resource($this->destination)) {
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
        }
    }
    
}

