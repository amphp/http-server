<?php

namespace Aerys\Http\BodyWriters;

use Aerys\Http\ResourceException;

class Resource extends BodyWriter {
    
    private $destination;
    private $source;
    private $contentLength;
    
    private $totalBytesWritten = 0;
    
    function __construct($destination, $source, $contentLength) {
        $this->destination = $destination;
        $this->source = $source;
        $this->contentLength = $contentLength;
    }
    
    function write() {
        $bytesWritten = @stream_copy_to_stream(
            $this->source,
            $this->destination,
            $this->granularity,
            $this->totalBytesWritten
        );
        
        $this->totalBytesWritten += $bytesWritten;
        
        if ($bytesWritten && ($this->totalBytesWritten == $this->contentLength)) {
            return TRUE;
        } elseif ($bytesWritten) {
            return FALSE;
        } elseif (!(is_resource($this->destination) && is_resource($this->source))) {
            throw new ResourceException(
                'Failed copying source stream to destination'
            );
        }
    }
    
}

