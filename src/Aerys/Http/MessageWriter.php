<?php

namespace Aerys\Http;

class MessageWriter {
    
    const START = 0;
    const HEADERS = 10;
    const BODY_NONE = 20;
    const BODY_IDENTITY = 30;
    const BODY_RESOURCE = 40;
    const BODY_TRAVERSABLE = 50;
    const BODY_TRAVERSABLE_CHUNKS = 60;
    const COMPLETE = 99;
    
    const CHUNK_DELIMITER = "\r\n";
    const FINAL_CHUNK = "0\r\n\r\n";
    
    private $state = self::START;
    private $ioResource;
    private $messageQueue = [];
    
    private $body;
    private $bodySize;
    private $bodyStyle;
    private $bodyBytesWritten = 0;
    
    private $priorityBuffer;
    
    private $writeBuffer;
    private $granularity = 8192;
    
    private $remainingChunkBytes = 0;
    private $hasBufferedFinalChunk;
    
    public function __construct($ioResource) {
        $this->ioResource = $ioResource;
    }
    
    public function enqueue(Message $msg) {
        $this->messageQueue[] = $msg;
    }
    
    public function priorityWrite($data) {
        $this->priorityBuffer .= $data;
    }
    
    /**
     * @throws ResourceException On destination stream write failure
     * @return Returns TRUE on write completion, FALSE otherwise
     */
    public function write() {
        if (!$this->messageQueue && $this->state == self::START && $this->priorityBuffer === NULL) {
            return FALSE;
        }
        
        switch ($this->state) {
            case self::START:
                goto start;
            case self::HEADERS:
                goto headers;
            case self::BODY_IDENTITY:
                goto body_identity;
            case self::BODY_RESOURCE:
                goto body_resource;
            case self::BODY_TRAVERSABLE:
                goto body_traversable;
            case self::BODY_TRAVERSABLE_CHUNKS:
                goto body_traversable_chunks;
        }
        
        start: {
            if ($this->priorityBuffer !== NULL && !$this->priority()) {
                return FALSE;
            }
            $this->start();
            goto headers;
        }
        
        headers: {
            $bytesWritten = @fwrite($this->ioResource, $this->writeBuffer);
            
            if ($bytesWritten && ($this->writeBuffer = substr($this->writeBuffer, $bytesWritten))) {
                goto further_write_required;
            } elseif ($bytesWritten) {
                goto body_start;
            } elseif (is_resource($this->ioResource)) {
                goto further_write_required;
            } else {
                throw new ResourceException(
                    'Failed writing to destination stream'
                );
            }
        }
        
        body_start: {
            $this->state = $this->bodyStyle;
            
            switch ($this->bodyStyle) {
                case self::BODY_NONE:
                    goto complete;
                case self::BODY_IDENTITY:
                    goto body_identity;
                case self::BODY_RESOURCE:
                    goto body_resource;
                case self::BODY_TRAVERSABLE:
                    goto body_traversable;
                case self::BODY_TRAVERSABLE_CHUNKS:
                    goto body_traversable_chunks;
            }
        }
        
        body_identity: {
            if ($this->bodyIdentity()) {
                goto complete;
            } else {
                goto further_write_required;
            }
        }
        
        body_resource: {
            if ($this->bodyResource()) {
                goto complete;
            } else {
                goto further_write_required;
            }
        }
        
        body_traversable: {
            if ($this->bodyTraversable()) {
                goto complete;
            } else {
                goto further_write_required;
            }
        }
        
        body_traversable_chunks: {
            if ($this->bodyTraversableChunks()) {
                goto complete;
            } else {
                goto further_write_required;
            }
        }
        
        further_write_required: {
            return FALSE;
        }
        
        complete: {
            $this->state = self::START;
            $this->body = NULL;
            $this->bodySize = NULL;
            $this->bodyStyle = NULL;
            $this->bodyBytesWritten = 0;
            $this->writeBuffer = NULL;
            $this->remainingChunkBytes = 0;
            $this->hasBufferedFinalChunk = NULL;
            
            return TRUE;
        }
    }
    
    private function start() {
        $msg = array_shift($this->messageQueue);
        
        $this->body = $msg->getBody();
        $this->writeBuffer = $msg->getStartLineAndHeaders();
        $this->state = self::HEADERS;
        
        if (!$this->body && $this->body !== '0') {
            $this->bodyStyle = self::BODY_NONE;
            return;
        }
        
        if (is_string($this->body)) {
            $this->bodyStyle = self::BODY_IDENTITY;
            $this->bodySize = strlen($this->body);
            return;
        }
        
        if (is_resource($this->body)) {
            fseek($this->body, 0, SEEK_END);
            $this->bodySize = ftell($this->body);
            rewind($this->body);
            $this->bodyStyle = self::BODY_RESOURCE;
        } elseif ($this->body instanceof \Iterator) {
            $canChunk = ($msg->getProtocol() >= 1.1);
            $this->bodyStyle = $canChunk ? self::BODY_TRAVERSABLE_CHUNKS : self::BODY_TRAVERSABLE;
        } else {
            throw new \InvalidArgumentException;
        }
    }
    
    private function priority() {
        $priorityLen = strlen($this->priorityBuffer);
        $bytesWritten = @fwrite($this->ioResource, $this->priorityBuffer, $this->granularity);
        
        if ($bytesWritten == $priorityLen) {
            $this->priorityBuffer = NULL;
            return TRUE;
        } elseif ($bytesWritten) {
            $this->priorityBuffer = substr($this->priorityBuffer, $bytesWritten);
            return FALSE;
        } elseif (!is_resource($this->ioResource)) {
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
        }
    }
    
    private function bodyIdentity() {
        $bytesWritten = @fwrite($this->ioResource, $this->body, $this->granularity);
        $this->bodyBytesWritten += $bytesWritten;
        
        if ($bytesWritten && ($this->bodyBytesWritten == $this->bodySize)) {
            return TRUE;
        } elseif ($bytesWritten) {
            $this->body = substr($this->body, $bytesWritten);
            return FALSE;
        } elseif (!is_resource($this->ioResource)) {
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
        }
    }
    
    private function bodyResource() {
        $bytesWritten = @stream_copy_to_stream(
            $this->body,
            $this->ioResource,
            $this->granularity,
            $this->bodyBytesWritten
        );
        
        $this->bodyBytesWritten += $bytesWritten;
        
        if ($bytesWritten && ($this->bodyBytesWritten == $this->bodySize)) {
            return TRUE;
        } elseif ($bytesWritten) {
            return FALSE;
        } elseif (!is_resource($this->ioResource)) {
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
        }
    }
    
    /**
     * An Iterator body will automatically use this method if the message protocol is less than 1.1.
     * Note that if a request message does not specify a `Content-Length` header when writing an
     * Iterator body using the 1.0 protocol it will result in an invalid messsage. It is the
     * responsibility of the calling application to ensure applicable headers exist for the message
     * it is writing.
     */
    private function bodyTraversable() {
        $this->writeBuffer .= $this->body->current();
        $this->body->next();
        if (!$this->writeBuffer && $this->writeBuffer !== '0') {
            return FALSE;
        }
        
        $bytesWritten = @fwrite($this->ioResource, $this->writeBuffer, $this->granularity);
        
        if ($bytesWritten && $bytesWritten == strlen($this->writeBuffer)) {
            $this->writeBuffer = NULL;
            return !$this->body->valid();
        } elseif ($bytesWritten) {
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
            return FALSE;
        } elseif (!is_resource($this->ioResource)) {
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
        }
    }
    
    private function bodyTraversableChunks() {
        if (!$this->writeBuffer && !$this->generateNextTraversableChunk()) {
            return FALSE;
        }
        
        $bytesWritten = @fwrite($this->ioResource, $this->writeBuffer, $this->granularity);
        
        if ($bytesWritten && $bytesWritten == $this->remainingChunkBytes) {
            $this->writeBuffer = NULL;
            $this->remainingChunkBytes = 0;
            return $this->hasBufferedFinalChunk;
        } elseif ($bytesWritten) {
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
            $this->remainingChunkBytes -= $bytesWritten;
            return FALSE;
        } elseif (!is_resource($this->ioResource)) {
            throw new ResourceException(
                'Failed writing to destination stream resource'
            );
        }
    }
    
    private function generateNextTraversableChunk() {
        if ($this->body->valid()) {
            $nextChunkData = $this->body->current();
            if (!$nextChunkData && $nextChunkData !== '0') {
                return FALSE;
            }
            
            $this->body->next();
            $chunkSize = strlen($nextChunkData);
            $this->writeBuffer = dechex($chunkSize) . self::CHUNK_DELIMITER . $nextChunkData . self::CHUNK_DELIMITER;
        } else {
            $this->writeBuffer = self::FINAL_CHUNK;
            $this->hasBufferedFinalChunk = TRUE;
        }
        
        $this->remainingChunkBytes = strlen($this->writeBuffer);
        
        return TRUE;
    }
    
}





































