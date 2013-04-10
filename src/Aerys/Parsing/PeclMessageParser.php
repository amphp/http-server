<?php

namespace Aerys\Parsing;

class PeclMessageParser extends MessageParser {
    
    protected function parseHeaders($rawHeaders) {
        if (!$headers = @http_parse_headers($rawHeaders)) {
            throw new HeaderSyntaxException;
        }
        
        $result = [];
        
        foreach ($headers as $field => $value) {
            $field = strtoupper($field);
            
            if (is_string($value)) {
                $result[$field][] = $value;
            } else {
                $result[$field] = $value;
            }
        }
        
        return $result;
    }
    
}

