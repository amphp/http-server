<?php

namespace Aerys\Io;

class MessageWriter {
    
    const START = 0;
    const HEADERS = 1;
    const BODY = 2;
    
    private $destination;
    private $bodyWriterFactory;
    private $state = self::START;
    private $granularity = 32768;
    private $toWriteQueue = [];
    
    private $headers;
    private $headerSize;
    private $body;
    private $bodyWriter;
    
    function __construct($destination, BodyWriterFactory $bodyWriterFactory) {
        $this->destination = $destination;
        $this->bodyWriterFactory = $bodyWriterFactory;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function enqueue($asgiResponse, $protocol, $contentLength) {
        $this->toWriteQueue[] = [$asgiResponse, $protocol, $contentLength];
    }
    
    /**
     * @throws ResourceException On destination stream write failure
     * @return bool Returns TRUE if a message write was completed, FALSE otherwise
     */
    function write() {
        if (!($this->state || $this->toWriteQueue)) {
            return FALSE;
        }
        
        switch ($this->state) {
            case self::START: goto start;
            case self::HEADERS: goto headers;
            case self::BODY: goto body;
        }
        
        start: {
            list($asgiResponse, $protocol, $contentLength) = array_shift($this->toWriteQueue);
            list($status, $reason, $headers, $body) = $asgiResponse;
            
            $this->body = $body;
            $this->headers = $this->generateStartLineAndHeaders($protocol, $status, $reason, $headers);
            $this->headerSize = strlen($this->headers);
            $this->state = self::HEADERS;
            
            if ($this->body || $this->body === '0') {
                $this->bodyWriter = $this->bodyWriterFactory->make(
                    $this->destination,
                    $body,
                    $protocol,
                    $contentLength
                );
                $this->bodyWriter->setGranularity($this->granularity);
            }
            
            goto headers;
        }
        
        headers: {
            $bytesWritten = @fwrite($this->destination, $this->headers, $this->granularity);
            
            if ($bytesWritten && $bytesWritten == $this->headerSize) {
                $this->headers = NULL;
                $this->headerSize = NULL;
                goto body_start;
            } elseif ($bytesWritten) {
                $this->headers = substr($this->headers, $bytesWritten);
                $this->headerSize -= $bytesWritten;
                goto further_write_needed;
            } elseif (is_resource($this->destination)) {
                goto further_write_needed;
            } else {
                throw new ResourceException(
                    'Failed writing to destination'
                );
            }
        }
        
        body_start: {
            if ($this->bodyWriter) {
                $this->state = self::BODY;
                goto body;
            } else {
                goto complete;
            }
        }
        
        body: {
            if ($this->bodyWriter->write()) {
                goto complete;
            } else {
                goto further_write_needed;
            }
        }
        
        complete: {
            $this->state = self::START;
            $this->body = NULL;
            $this->bodyWriter = NULL;
            
            if ($this->toWriteQueue) {
                goto start;
            } else {
                return $this->toWriteQueue ? count($this->toWriteQueue) : 0;
            }
        }
        
        further_write_needed: {
            return -1;
        }
    }
    
    private function generateStartLineAndHeaders($protocol, $status, $reason, array $headers) {
        $msg = "HTTP/$protocol $status";
        
        if ($reason || $reason === '0') {
            $msg .= " $reason";
        }
        
        $msg .= "\r\n";
        
        foreach ($headers as $header => $value) {
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    $msg .= "$header: $nestedValue\r\n";
                }
            } else {
                $msg .= "$header: $value\r\n";
            }
        }
        
        $msg .= "\r\n";
        
        return $msg;
    }
    
}

