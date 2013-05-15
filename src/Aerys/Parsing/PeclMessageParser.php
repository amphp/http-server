<?php

namespace Aerys\Parsing;

class PeclMessageParser implements Parser {
    
    private $mode;
    private $state = self::AWAITING_HEADERS;
    private $buffer = '';
    private $traceBuffer;
    private $protocol;
    private $requestMethod;
    private $requestUri;
    private $responseCode;
    private $responseReason;
    private $headers = [];
    private $body;
    private $remainingBodyBytes;
    private $bodyBytesConsumed = 0;
    private $bodyChunksFilter;
    
    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = 10485760;
    private $bodySwapSize = 2097152;
    private $returnHeadersBeforeBody = FALSE;
    
    function __construct($mode = self::MODE_REQUEST) {
        $this->mode = $mode;
    }
    
    function setOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }
    }
    
    function hasUnfinishedMessage() {
        return $this->state || ltrim($this->buffer);
    }
    
    function hasBuffer() {
        return ($this->buffer || $this->buffer === '0');
    }
    
    function parse($data) {
        $this->buffer .= $data;
        
        switch ($this->state) {
            case self::AWAITING_HEADERS:
                goto awaiting_headers;
            case self::BODY_IDENTITY:
                goto body_identity;
            case self::BODY_IDENTITY_EOF:
                goto body_identity_eof;
            case self::BODY_CHUNKS:
                goto body_chunks;
            case self::TRAILERS_START:
                goto trailers_start;
            case self::TRAILERS:
                goto trailers;
        }
        
        awaiting_headers: {
            if (!$startLineAndHeaders = $this->shiftHeadersFromMessageBuffer()) {
                goto more_data_needed;
            } elseif ($this->mode === self::MODE_REQUEST) {
                goto request_line_and_headers;
            } else {
                goto status_line_and_headers;
            }
        }
        
        request_line_and_headers: {
            $msgObj = @http_parse_message($startLineAndHeaders);
            
            if (!($msgObj && $msgObj->type == self::MODE_REQUEST)) {
                throw new ParseException;
            }
            
            $this->protocol = $msgObj->httpVersion == 1 ? '1.0' : $msgObj->httpVersion;
            $this->requestMethod = $msgObj->requestMethod;
            $this->requestUri = $msgObj->requestUrl;
            $this->headers = $this->normalizeHeaders($msgObj->headers);
            
            goto transition_from_request_headers_to_body;
        }
        
        transition_from_request_headers_to_body: {
            if ($this->requestMethod == 'HEAD' || $this->requestMethod == 'TRACE' || $this->requestMethod == 'OPTIONS') {
                goto complete;
            } elseif (isset($this->headers['TRANSFER-ENCODING'][0])
                && strcasecmp($this->headers['TRANSFER-ENCODING'][0], 'identity')
            ) {
                $this->state = self::BODY_CHUNKS;
                goto before_body;
            } elseif (isset($this->headers['CONTENT-LENGTH'][0])) {
                $this->remainingBodyBytes = (int) $this->headers['CONTENT-LENGTH'][0];
                $this->state = self::BODY_IDENTITY;
                goto before_body;
            } else {
                goto complete;
            }
        }
        
        status_line_and_headers: {
            $msgObj = @http_parse_message($startLineAndHeaders);
            
            if (!($msgObj && $msgObj->type == self::MODE_RESPONSE)) {
                throw new ParseException;
            }
            
            $this->protocol = $msgObj->httpVersion == 1 ? '1.0' : $msgObj->httpVersion;
            $this->responseCode = $msgObj->responseCode;
            $this->responseReason = $msgObj->responseStatus;
            $this->headers = $this->normalizeHeaders($msgObj->headers);
            
            goto transition_from_response_headers_to_body;
        }
        
        transition_from_response_headers_to_body: {
            if ($this->responseCode == 204 || $this->responseCode == 304 || $this->responseCode < 200) {
                goto complete;
            } elseif (isset($this->headers['TRANSFER-ENCODING'][0])
                && strcasecmp($this->headers['TRANSFER-ENCODING'][0], 'identity')
            ) {
                $this->state = self::BODY_CHUNKS;
                goto before_body;
            } elseif (isset($this->headers['CONTENT-LENGTH'][0])) {
                $this->remainingBodyBytes = (int) $this->headers['CONTENT-LENGTH'][0];
                $this->state = self::BODY_IDENTITY;
                goto before_body;
            } else {
                $this->state = self::BODY_IDENTITY_EOF;
                goto before_body;
            }
        }
        
        before_body: {
            if ($this->remainingBodyBytes === 0) {
                goto complete;
            }
            
            $uri = 'php://temp/maxmemory:' . $this->bodySwapSize;
            $this->body = fopen($uri, 'r+');
            
            if ($this->returnHeadersBeforeBody) {
                $parsedMsgArr = $this->getParsedMessageArray();
                $parsedMsgArr['headersOnly'] = TRUE;
                
                return $parsedMsgArr;
            }
            
            switch ($this->state) {
                case self::BODY_IDENTITY:
                    goto body_identity;
                case self::BODY_IDENTITY_EOF:
                    goto body_identity_eof;
                case self::BODY_CHUNKS:
                    $filter = stream_filter_append($this->body, 'dechunk', STREAM_FILTER_WRITE);
                    $this->bodyChunksFilter = $filter;
                    goto body_chunks;
                default:
                    throw new \RuntimeException(
                        'Unexpected parse state encountered'
                    );
            }
        }
        
        body_identity: {
            $bufferDataSize = strlen($this->buffer);
        
            if ($bufferDataSize === $this->remainingBodyBytes) {
                $this->addToBody($this->buffer);
                $this->buffer = NULL;
                $this->remainingBodyBytes = 0;
                goto complete;
            } elseif ($bufferDataSize < $this->remainingBodyBytes) {
                $this->addToBody($this->buffer);
                $this->buffer = NULL;
                $this->remainingBodyBytes -= $bufferDataSize;
                goto more_data_needed;
            } else {
                $bodyData = substr($this->buffer, 0, $this->remainingBodyBytes);
                $this->addToBody($bodyData);
                $this->buffer = substr($this->buffer, $this->remainingBodyBytes);
                $this->remainingBodyBytes = 0;
                goto complete;
            }
        }
        
        body_identity_eof: {
            $this->addToBody($this->buffer);
            $this->buffer = '';
            goto more_data_needed;
        }
        
        body_chunks: {
            if (FALSE !== ($chunksEndPos = strpos($this->buffer, "0\r\n"))) {
                $chunksEndPos += 3;
                $chunkedData = substr($this->buffer, 0, $chunksEndPos);
                $this->buffer = substr($this->buffer, $chunksEndPos);
                $this->addToBody($chunkedData);
                stream_filter_remove($this->bodyChunksFilter);
                $this->state = self::TRAILERS_START;
                goto trailers_start;
            } else {
                $chunkedData = substr($this->buffer, 0, -2);
                $this->buffer = substr($this->buffer, -2);
                $this->addToBody($chunkedData);
                goto more_data_needed;
            }
        }
        
        trailers_start: {
            $firstTwoBytes = substr($this->buffer, 0, 2);
            
            if ($firstTwoBytes === FALSE || $firstTwoBytes === "\r") {
                goto more_data_needed;
            } elseif ($firstTwoBytes === "\r\n") {
                $this->buffer = substr($this->buffer, 2);
                goto complete;
            } else {
                $this->state = self::TRAILERS;
                goto trailers;
            }
        }
        
        trailers: {
            if ($trailers = $this->shiftHeadersFromMessageBuffer()) {
                $this->parseTrailers($trailers);
                goto complete;
            } else {
                goto more_data_needed;
            }
        }
        
        complete: {
            $parsedMsgArr = $this->getParsedMessageArray();
            $parsedMsgArr['headersOnly'] = FALSE;
            
            $this->state = self::AWAITING_HEADERS;
            $this->traceBuffer = NULL;
            $this->headers = [];
            $this->body = NULL;
            $this->bodyBytesConsumed = 0;
            $this->remainingBodyBytes = NULL;
            $this->bodyChunksFilter = NULL;
            $this->protocol = NULL;
            $this->requestUri = NULL;
            $this->requestMethod = NULL;
            $this->responseCode = NULL;
            $this->responseReason = NULL;
            
            return $parsedMsgArr;
        }
        
        more_data_needed: {
            return NULL;
        }
    }
    
    private function shiftHeadersFromMessageBuffer() {
        ltrim($this->buffer);
        
        if ($headersSize = strpos($this->buffer, "\r\n\r\n")) {
            $headers = substr($this->buffer, 0, $headersSize + 2);
            $this->buffer = substr($this->buffer, $headersSize + 4);
        } elseif ($headersSize = strpos($this->buffer, "\n\n")) {
            $headers = substr($this->buffer, 0, $headersSize + 1);
            $this->buffer = substr($this->buffer, $headersSize + 2);
        } else {
            $headersSize = strlen($this->buffer);
            $headers = NULL;
        }
        
        if ($headersSize > $this->maxHeaderBytes) {
            throw new ParseException(NULL, 431);
        }
        
        return $headers;
    }
    
    private function normalizeHeaders(array $headers) {
        $normalized = [];
        $headers = array_change_key_case($headers, CASE_UPPER);
        
        foreach ($headers as $field => $value) {
            if (is_string($value)) {
                $normalized[$field][] = $value;
            } else {
                $normalized[$field] = $value;
            }
        }
        
        return $normalized;
    }
    
    private function parseTrailers($trailers) {
        if (!$headers = http_parse_headers($trailers)) {
            throw new ParseException(NULL, 400);
        }
        
        $headers = $this->normalizeHeaders($headers);
        
        if (isset($headers['TRANSFER-ENCODING'])
            || isset($headers['CONTENT-LENGTH'])
            || isset($headers['TRAILER'])
        ) {
            throw new ParseException(NULL, 400);
        } else {
            $this->headers = array_merge($this->headers, $headers);
        }
    }
    
    private function getParsedMessageArray() {
        $headers = [];
        
        foreach ($this->headers as $key => $arr) {
            $headers[$key] = isset($arr[1]) ? $arr : $arr[0];
        }
        
        if ($this->body) {
            rewind($this->body);
        }
        
        $result = [
            'protocol' => $this->protocol,
            'headers'  => $headers,
            'body'     => $this->body,
            'trace'    => $this->traceBuffer
        ];
        
        if ($this->mode === self::MODE_REQUEST) {
            $result['method'] = $this->requestMethod;
            $result['uri'] = $this->requestUri;
        } else {
            $result['status'] = $this->responseCode;
            $result['reason'] = $this->responseReason;
        }
        
        return $result;
    }
    
    private function addToBody($data) {
        $this->bodyBytesConsumed += strlen($data);
        
        if ($this->maxBodyBytes && $this->bodyBytesConsumed > $this->maxBodyBytes) {
            throw new ParseException(NULL, 413);
        } else {
            fseek($this->body, 0, SEEK_END);
            fwrite($this->body, $data);
        }
    }
    
}

