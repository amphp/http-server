<?php

namespace Aerys\Http\Io;

use Aerys\Http\HttpServer;

class RequestParser extends MessageParser {
    
    const REQUEST_LINE_PATTERN = "#^
        (?P<method>[^\(\)<>@,;:\\\"/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+)[\x20\x09]+
        (?P<uri>[a-zA-Z0-9\$\-_\.\+!*'\(\),;/\?\:\@\=\&]+)[\x20\x09]+
        HTTP/(?P<protocol>\d+\.\d+)[\x0D]?
    $#ix";
    
    private $method;
    private $uri;
    private $protocol;
    
    /**
     * @throws ParseException On invalid request line
     * @return void
     */
    protected function parseStartLine($rawStartLine) {
        if (preg_match(self::REQUEST_LINE_PATTERN, $rawStartLine, $m)) {
            $this->method = $m['method'];
            $this->uri = $m['uri'];
            $this->protocol = $m['protocol'];
        } else {
            throw new ParseException(
                "Invalid request line",
                self::E_START_LINE_SYNTAX
            );
        }
        
        if (!($this->protocol == '1.1' || $this->protocol == '1.0')) {
            throw new ParseException(
                'Protocol not supported',
                self::E_PROTOCOL_NOT_SUPPORTED
            );
        }
    }
    
    protected function allowsEntityBody() {
        return !($this->method == HttpServer::HEAD || $this->method == HttpServer::TRACE);
    }
    
    protected function getParsedMessageVals() {
        return array(
            'method' => $this->method,
            'uri' => $this->uri,
            'protocol' => $this->protocol,
            'headers' => $this->getHeaders(),
            'body' => $this->getBody()
        );
    }
    
    protected function resetForNextMessage() {
        parent::resetForNextMessage();
        
        $this->method = NULL;
        $this->uri = NULL;
        $this->protocol = NULL;
    }
    
    function getMethod() {
        return $this->method;
    }
    
    function getUri() {
        return $this->uri;
    }
    
    function getProtocol() {
        return $this->protocol;
    }
    
}
