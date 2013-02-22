<?php

namespace Aerys\Http\Io;

use Aerys\Http\HttpServer;

class RequestParser extends MessageParser {
    
    const REQUEST_LINE_PATTERN = "#^
        (?P<method>[^\(\)<>@,;:\\\"/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+)[\x20\x09]+
        (?P<uri>[a-zA-Z0-9\$\-_\.\+!*'\(\),;/\?\:\@\=\&]+)[\x20\x09]+
        HTTP/(?P<protocol>\d+\.\d+)[\x0D]?
    $#ix";
    
    protected $method;
    protected $uri;
    
    protected function parseStartLine($rawStartLine) {
        if (preg_match(self::REQUEST_LINE_PATTERN, $rawStartLine, $m)) {
            $this->method   = $m['method'];
            $this->uri      = $m['uri'];
            $this->protocol = $m['protocol'];
        } else {
            throw new ParseException(
                "Invalid request line",
                self::E_START_LINE_SYNTAX
            );
        }
    }
    
    protected function allowsEntityBody() {
        $method = strtoupper($this->method);
        return !($method == HttpServer::HEAD || $method == HttpServer::TRACE);
    }
    
    protected function getParsedMessageVals() {
        $headers = [];
        foreach ($this->headers as $key => $arr) {
            $headers[$key] = isset($arr[1]) ? $arr : $arr[0];
        }
        
        return [
            'method'   => $this->method,
            'uri'      => $this->uri,
            'protocol' => $this->protocol,
            'headers'  => $headers,
            'body'     => $this->body,
            'trace'    => $this->traceBuffer
        ];
    }
    
    protected function resetForNextMessage() {
        $this->state = self::START_LINE;
        $this->traceBuffer = NULL;
        $this->headers = [];
        $this->body = NULL;
        $this->bodyBytesConsumed = 0;
        $this->remainingBodyBytes = NULL;
        $this->currentChunkSize = NULL;
        $this->protocol = NULL;
        $this->method = NULL;
        $this->uri = NULL;
    }
    
}

