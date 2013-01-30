<?php

namespace Aerys\Http;

class MessageWriter {
    
    const START = 0;
    const HEADERS = 10;
    const BODY_NONE = 20;
    const BODY_IDENTITY = 30;
    const BODY_RESOURCE = 40;
    const BODY_RESOURCE_CHUNKS = 50;
    const BODY_TRAVERSABLE = 60;
    const BODY_TRAVERSABLE_CHUNKS = 60;
    const COMPLETE = 99;
    
    private $state = self::START;
    private $ioResource;
    private $messageQueue = [];
    
    private $body;
    private $bodySize;
    private $bodyStyle;
    private $bodyIsChunked;
    private $bodyBytesWritten = 0;
    
    private $onComplete;
    
    private $writeBuffer;
    private $granularity = 8192;
    
    public function __construct($ioResource) {
        $this->ioResource = $ioResource;
    }
    
    public function enqueue(Message $msg) {
        $this->messageQueue[] = $msg;
    }
    
    /**
     * @throws ResourceException On destination stream write failure
     * @return Returns TRUE on write completion, FALSE otherwise
     */
    public function write() {
        if (!$this->messageQueue && $this->state == self::START) {
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
            case self::BODY_RESOURCE_CHUNKS:
                goto body_resource_chunks;
            case self::BODY_TRAVERSABLE:
                goto body_traversable;
            case self::BODY_TRAVERSABLE_CHUNKS:
                goto body_traversable_chunks;
        }
        
        start: {
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
                case self::BODY_RESOURCE_CHUNKS:
                    goto body_resource_chunks;
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
        
        body_resource_chunks: {
            // @todo
        }
        
        body_traversable: {
            // @todo
        }
        
        body_traversable_chunks: {
            // @todo
        }
        
        further_write_required: {
            return FALSE;
        }
        
        complete: {
            $this->state = self::START;
            $this->body = NULL;
            $this->bodySize = NULL;
            $this->bodyStyle = NULL;
            $this->bodyIsChunked = NULL;
            $this->bodyBytesWritten = 0;
            $this->writeBuffer = NULL;
            
            return TRUE;
        }
    }
    
    private function start() {
        $msg = array_shift($this->messageQueue);
        
        $this->body = $msg->getBody();
        $this->writeBuffer = $msg->getStartLineAndHeaders();
        $this->state = self::HEADERS;
        
        if (empty($this->body) && $this->body !== '0') {
            $this->bodyStyle = self::BODY_NONE;
            return;
        }
        
        if (is_string($this->body)) {
            $this->bodyStyle = self::BODY_IDENTITY;
            $this->bodySize = strlen($this->body);
            return;
        }
        
        $headers = $msg->getHeaders();
        
        if ($headers) {
            $keys = array_map('strtoupper', array_keys($headers));
            $headers = array_combine($keys, $headers);
        }
        
        if ($bodyIsChunked = isset($headers['TRANSFER-ENCODING'])) {
            $bodyIsChunked = ('chunked' === strtolower($headers['TRANSFER-ENCODING']));
        }
        
        if (is_resource($this->body)) {
            fseek($this->body, 0, SEEK_END);
            $this->bodySize = ftell($this->body);
            rewind($this->body);
            stream_set_blocking($this->body, FALSE);
            $this->bodyStyle = $bodyIsChunked ? self::BODY_RESOURCE_CHUNKED : self::BODY_RESOURCE;
        } elseif ($this->body instanceof \Traversable || is_array($this->body)) {
            $this->bodyStyle = $bodyIsChunked ? self::BODY_TRAVERSABLE_CHUNKED : self::BODY_TRAVERSABLE;
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
    
    private function bodyResourceChunks() {
        // @todo
    }
    
    private function bodyTraversable() {
        // @todo
    }
    
    private function bodyTraversableChunks() {
        // @todo
    }
}
