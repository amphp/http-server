<?php

namespace Aerys;

use Aerys\Writing\BodyWriterFactory,
    Aerys\Writing\MessageWriter,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser;

class PipelineFactory {
    
    private $bodyWriterFactory;
    
    function __construct(BodyWriterFactory $bwf = NULL) {
        $this->bodyWriterFactory = $bwf ?: new BodyWriterFactory;
        $this->canUsePeclHttp = $this->canUsePeclHttp();
    }
    
    protected function canUsePeclHttp() {
        return extension_loaded('http') && function_exists('http_parse_headers');
    }
    
    function makePipeline($clientSocket, $peerName, $serverName) {
        $writer = new MessageWriter($clientSocket, $this->bodyWriterFactory);
        $parser = $this->canUsePeclHttp
            ? new PeclMessageParser($clientSocket, MessageParser::MODE_REQUEST)
            : new MessageParser($clientSocket, MessageParser::MODE_REQUEST);
        
        return new Pipeline($clientSocket, $peerName, $serverName, $parser, $writer);
    }
    
}

