<?php

namespace Aerys;

use Aerys\Writing\WriterFactory,
    Aerys\Parsing\MessageParser,
    Aerys\Parsing\PeclMessageParser;

class PipelineFactory {
    
    private $writerFactory;
    private $maxStartLineSize = 2048;
    private $maxHeadersSize = 8192;
    private $maxEntityBodySize = 10485760;
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
            ? new PeclMessageParser(MessageParser::MODE_REQUEST)
            : new MessageParser(MessageParser::MODE_REQUEST);
        
        $parser->setAllOptions([
            'returnHeadersBeforeBody' => TRUE,
            'maxStartLineBytes' => $this->maxStartLineSize,
            'maxHeaderBytes' => $this->maxHeadersSize,
            'maxBodyBytes' => $this->maxEntityBodySize,
            'bodySwapSize' => $this->bodySwapSize
        ]);
        
        return new Pipeline($socket, $parser, $this->writerFactory);
    }
    
    function setParserMaxStartLineSize($bytes) {
        $this->maxStartLineSize = (int) $bytes;
    }
    
    function setParserMaxHeadersSize($bytes) {
        $this->maxHeadersSize = (int) $bytes;
    }
    
    function setParserMaxEntityBodySize($bytes) {
        $this->maxEntityBodySize = (int) $bytes;
    }
    
    function setParserBodySwapSize($bytes) {
        $this->bodySwapSize = (int) $bytes;
    }
}
