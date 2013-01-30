<?php

namespace Aerys\Http;

class RequestParser extends MessageParser {
    
    const HEAD = 'HEAD';
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
    }
    
    protected function allowsEntityBody() {
        return ($this->method !== self::HEAD);
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
