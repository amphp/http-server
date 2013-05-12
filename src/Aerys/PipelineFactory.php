<?php

namespace Aerys;

use Aerys\Parsing\Parser,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser,
    Aerys\Writing\WriterFactory;

class PipelineFactory {
    
    private $writerFactory;
    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = 10485760;
    private $bodySwapSize = 2097152;
    
    function __construct(WriterFactory $writerFactory = NULL) {
        $this->writerFactory = $writerFactory ?: new WriterFactory;
        $this->canUsePeclHttp = $this->canUsePeclHttp();
    }
    
    protected function canUsePeclHttp() {
        return extension_loaded('http') && function_exists('http_parse_headers');
    }
    
    function makePipeline($socket) {
        $parser = $this->canUsePeclHttp
            ? new PeclMessageParser(Parser::MODE_REQUEST)
            : new MessageParser(Parser::MODE_REQUEST);
        
        $parser->setOptions([
            'returnHeadersBeforeBody' => TRUE,
            'maxHeaderBytes' => $this->maxHeaderBytes,
            'maxBodyBytes' => $this->maxBodyBytes,
            'bodySwapSize' => $this->bodySwapSize
        ]);
        
        return new Pipeline($socket, $parser, $this->writerFactory);
    }
    
    function setParserMaxHeaderBytes($bytes) {
        $this->maxHeaderBytes = (int) $bytes;
    }
    
    function setParserMaxBodyBytes($bytes) {
        $this->maxBodyBytes = (int) $bytes;
    }
    
    function setParserBodySwapSize($bytes) {
        $this->bodySwapSize = (int) $bytes;
    }
}
