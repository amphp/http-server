<?php

namespace Aerys\Parsing;

use Aerys\Status;

class PeclMessageParser extends MessageParser {
    
    protected function parseStartLineAndHeaders($startLineAndHeaders) {
        return ($this->mode === self::MODE_REQUEST)
            ? $this->parseRequestHeaders($startLineAndHeaders)
            : $this->parseResponseHeaders($startLineAndHeaders);
    }
    
    protected function parseRequestHeaders($startLineAndHeaders) {
        $msgObj = @http_parse_message($startLineAndHeaders);
        
        if (!($msgObj && $msgObj->type == 1)) {
            throw new ParseException(NULL, Status::BAD_REQUEST);
        }
        
        $this->protocol = $msgObj->httpVersion == 1 ? '1.0' : $msgObj->httpVersion;
        $this->requestMethod = $msgObj->requestMethod;
        $this->requestUri = $msgObj->requestUrl;
        
        $this->parseHeaders($msgObj);
    }
    
    protected function parseHeaders($msgObj) {
        $headers = [];
        
        $msgObj->headers = array_change_key_case($msgObj->headers, CASE_UPPER);
        
        foreach ($msgObj->headers as $field => $value) {
            if (is_string($value)) {
                $headers[$field][] = $value;
            } else {
                $headers[$field] = $value;
            }
        }
        
        $this->headers = $headers;
    }
    
    protected function parseResponseHeaders($startLineAndHeaders) {
        $msgObj = @http_parse_message($startLineAndHeaders);
        
        if (!($msgObj && $msgObj->type == 2)) {
            throw new ParseException;
        }
        
        $this->protocol = $msgObj->httpVersion == 1 ? '1.0' : $msgObj->httpVersion;
        $this->responseCode = $msgObj->responseCode;
        $this->responseReason = $msgObj->responseStatus;
        
        $this->parseHeaders($msgObj);
    }
    
}

