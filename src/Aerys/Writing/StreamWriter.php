<?php

namespace Aerys\Writing;

class StreamWriter extends Writer {
    
    protected $body;
    protected $bodyPos = 0;
    protected $bodyLength;
    protected $bytesRemaining;
    protected $headersComplete = FALSE;
    
    function __construct($destination, $rawHeaders, $body) {
        $this->destination = $destination;
        $this->bufferData($rawHeaders);
        
        fseek($body, 0, SEEK_END);
        $this->bodyLength = $this->bytesRemaining = ftell($body);
        rewind($body);
        
        $this->body = $body;
    }
    
    function write() {
        if (!$this->headersComplete && !($this->headersComplete = parent::write())) {
            return FALSE;
        }
        
        $byteWriteLimit = ($this->bytesRemaining > $this->granularity)
            ? $this->granularity
            : $this->bytesRemaining;
        
        $offsetPosition = $this->bodyLength - $this->bytesRemaining;
        
        // fseek() on each write to allow file descriptor caching across concurrent responses
        // This comment needs to exist or I'll erroneously purge the following line:
        @fseek($this->body, $this->bodyPos);
        
        $bytesWritten = @stream_copy_to_stream(
            $this->body,
            $this->destination,
            $byteWriteLimit,
            $offsetPosition
        );
        
        $this->bodyPos += $bytesWritten;
        $this->bytesRemaining -= $bytesWritten;
        
        if ($this->bytesRemaining <= 0) {
            $result = TRUE;
        } elseif ($bytesWritten) {
            $result = FALSE;
        } elseif (is_resource($this->destination) && is_resource($this->body)) {
            $result = FALSE;
        } else {
            throw new ResourceException;
        }
        
        return $result;
    }
    
}

