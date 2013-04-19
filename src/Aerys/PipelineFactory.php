<?php

namespace Aerys;

use Aerys\Writing\WriterFactory,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser;

class PipelineFactory {
    
    private $writerFactory;
    
    function __construct(WriterFactory $writerFactory = NULL) {
        $this->writerFactory = $writerFactory ?: new WriterFactory;
        $this->canUsePeclHttp = $this->canUsePeclHttp();
    }
    
    // This method is *only* protected to allow test mocking
    protected function canUsePeclHttp() {
        return extension_loaded('http') && function_exists('http_parse_headers');
    }
    
    function makePipeline($connection) {
        $socket = $connection->getSocket();
        
        $parser = $this->canUsePeclHttp
            ? new PeclMessageParser($socket, MessageParser::MODE_REQUEST)
            : new MessageParser($socket, MessageParser::MODE_REQUEST);
        
        return new Pipeline($connection, $parser, $this->writerFactory);
    }
    
}

