<?php

namespace Aerys\Http\Io;

class MessageWriter implements \Aerys\Pipeline\Writer {
    
    const START = 0;
    const HEADERS = 1;
    const BODY = 2;
    
    private $destination;
    private $bodyWriterFactory;
    private $state = self::START;
    private $granularity = 8192;
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
    
    function enqueue($asgiResponseAndProtocol) {
        $this->toWriteQueue[] = $asgiResponseAndProtocol;
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
            $nextMsg = array_shift($this->toWriteQueue);
            list($status, $reason, $headers, $body) = $nextMsg;
            
            // end() is used because the protocol may be at index 4 or 5 depending on whether or
            // not the userland handler assigned a 101 Switching Protocols callback at index 4
            $protocol = end($nextMsg);
            
            $contentLength = empty($headers['CONTENT-LENGTH']) ? NULL : $headers['CONTENT-LENGTH'];
            
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
            $bytesWritten = @fwrite($this->destination, $this->headers);
            
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
            
            return TRUE;
        }
        
        further_write_needed: {
            return FALSE;
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

