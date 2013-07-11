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
    private $chunkLenRemaining;
    
    private $parseFlowHeaders = [
        'TRANSFER-ENCODING' => NULL,
        'CONTENT-LENGTH' => NULL
    ];
    
    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = 10485760;
    private $storeBody = TRUE;
    private $beforeBody;
    private $onBodyData;
    
    private static $availableOptions = [
        'maxHeaderBytes' => 1,
        'maxBodyBytes' => 1,
        'storeBody' => 1,
        'beforeBody' => 1,
        'onBodyData' => 1
    ];
    
    function __construct($mode = self::MODE_REQUEST) {
        $this->mode = $mode;
    }
    
    function setOptions(array $options) {
        if ($options = array_intersect_key($options, self::$availableOptions)) {
            foreach ($options as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
    
    function getBuffer() {
        return $this->buffer;
    }
    
    function getState() {
        return $this->state;
    }
    
    function parse($data) {
        $this->buffer .= $data;
        
        if (!($this->buffer || $this->buffer === '0')) {
            goto more_data_needed;
        }
        
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
            }
            
            $this->traceBuffer = $startLineAndHeaders;
            
            if ($this->mode === self::MODE_REQUEST) {
                goto request_line_and_headers;
            } else {
                goto status_line_and_headers;
            }
        }
        
        request_line_and_headers: {
            $msgObj = @http_parse_message($startLineAndHeaders);
            
            if (!($msgObj && $msgObj->type == self::MODE_REQUEST)) {
                $this->requestMethod = $this->requestUri = $this->protocol = '?';
                throw new ParseException(
                    $this->getParsedMessageArray(),
                    $msg = 'Invalid request line and/or headers',
                    $code = 400,
                    $previousException = NULL
                );
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
            } elseif ($this->parseFlowHeaders['TRANSFER-ENCODING']) {
                $this->state = self::BODY_CHUNKS;
                goto before_body;
            } elseif ($this->parseFlowHeaders['CONTENT-LENGTH']) {
                $this->remainingBodyBytes = $this->parseFlowHeaders['CONTENT-LENGTH'];
                $this->state = self::BODY_IDENTITY;
                goto before_body;
            } else {
                goto complete;
            }
        }
        
        status_line_and_headers: {
            $msgObj = @http_parse_message($startLineAndHeaders);
            
            if (!($msgObj && $msgObj->type == self::MODE_RESPONSE)) {
                throw new ParseException(
                    $this->getParsedMessageArray(),
                    $msg = 'Invalid start line and/or headers',
                    $code = 400,
                    $previousException = NULL
                );
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
            } elseif ($this->parseFlowHeaders['TRANSFER-ENCODING']) {
                $this->state = self::BODY_CHUNKS;
                goto before_body;
            } elseif ($this->parseFlowHeaders['CONTENT-LENGTH'] === NULL) {
                $this->state = self::BODY_IDENTITY_EOF;
                goto before_body;
            } elseif ($this->parseFlowHeaders['CONTENT-LENGTH'] > 0) {
                $this->remainingBodyBytes = $this->parseFlowHeaders['CONTENT-LENGTH'];
                $this->state = self::BODY_IDENTITY;
                goto before_body;
            } else {
                goto complete;
            }
        }
        
        before_body: {
            if ($this->remainingBodyBytes === 0) {
                goto complete;
            }
            
            $this->body = fopen('php://memory', 'r+');
            
            if ($beforeBody = $this->beforeBody) {
                $parsedMsgArr = $this->getParsedMessageArray();
                $parsedMsgArr['headersOnly'] = TRUE;
                
                $beforeBody($parsedMsgArr);
            }
            
            switch ($this->state) {
                case self::BODY_IDENTITY:
                    goto body_identity;
                case self::BODY_IDENTITY_EOF:
                    goto body_identity_eof;
                case self::BODY_CHUNKS:
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
            if ($this->dechunk()) {
                $this->state = self::TRAILERS_START;
                goto trailers_start;
            } else {
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
            $this->chunkLenRemaining = NULL;
            $this->protocol = NULL;
            $this->requestUri = NULL;
            $this->requestMethod = NULL;
            $this->responseCode = NULL;
            $this->responseReason = NULL;
            $this->parseFlowHeaders = [
                'TRANSFER-ENCODING' => NULL,
                'CONTENT-LENGTH' => NULL
            ];
            
            return $parsedMsgArr;
        }
        
        more_data_needed: {
            return NULL;
        }
    }
    
    private function shiftHeadersFromMessageBuffer() {
        $this->buffer = ltrim($this->buffer, "\r\n");
        
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
        
        if ($this->maxHeaderBytes > 0 && $headersSize > $this->maxHeaderBytes) {
            throw new PolicyException(
                $this->getParsedMessageArray(),
                $msg = "Maximum allowable header size exceeded: {$this->maxHeaderBytes}",
                $code = 431,
                $previousException = NULL
            );
        }
        
        return $headers;
    }
    
    private function normalizeHeaders(array $headers) {
        $normalized = [];
        
        foreach ($headers as $field => $value) {
            if ($value === (array) $value) {
                $normalized[$field] = $value;
            } else {
                $normalized[$field][] = $value;
            }
        }
        
        $ucKeyHeaders = array_change_key_case($normalized, CASE_UPPER);
        
        if (isset($ucKeyHeaders['TRANSFER-ENCODING'])
            && strcasecmp('identity', $ucKeyHeaders['TRANSFER-ENCODING'][0])
        ) {
            $this->parseFlowHeaders['TRANSFER-ENCODING'] = TRUE;
        } elseif (isset($ucKeyHeaders['CONTENT-LENGTH'])) {
            $this->parseFlowHeaders['CONTENT-LENGTH'] = (int) $ucKeyHeaders['CONTENT-LENGTH'][0];
        }
        
        return $normalized;
    }
    
    private function dechunk() {
        if ($this->chunkLenRemaining !== NULL) {
            goto dechunk;
        }
        
        determine_chunk_size: {
            if (FALSE === ($lineEndPos = strpos($this->buffer, "\r\n"))) {
                goto more_data_needed;
            } elseif ($lineEndPos === 0) {
                throw new ParseException(
                    $this->getParsedMessageArray(),
                    $msg = 'Invalid new line; hexadecimal chunk size expected',
                    $code = 400,
                    $previousException = NULL
                );
            }
            
            $line = substr($this->buffer, 0, $lineEndPos);
            $hex = strtolower(trim(ltrim($line, '0'))) ?: 0;
            $dec = hexdec($hex);
            
            if ($hex == dechex($dec)) {
                $this->chunkLenRemaining = $dec;
            } else {
                throw new ParseException(
                    $this->getParsedMessageArray(),
                    $msg = 'Invalid hexadecimal chunk size',
                    $code = 400,
                    $previousException = NULL
                );
            }
            
            $this->buffer = substr($this->buffer, $lineEndPos + 2);
            
            if (!$dec) {
                return TRUE;
            }
        }
        
        dechunk: {
            $bufferLen = strlen($this->buffer);
            
            // These first two (extreme) edge cases prevent errors where the packet boundary ends after
            // the \r and before the \n at the end of a chunk.
            if ($bufferLen === $this->chunkLenRemaining) {
                
                goto more_data_needed;
                
            } elseif ($bufferLen === $this->chunkLenRemaining + 1) {
                
                goto more_data_needed;
                
            } elseif ($bufferLen >= $this->chunkLenRemaining + 2) {
                $chunk = substr($this->buffer, 0, $this->chunkLenRemaining);
                $this->buffer = substr($this->buffer, $this->chunkLenRemaining + 2);
                $this->chunkLenRemaining = NULL;
                $this->addToBody($chunk);
                
                goto determine_chunk_size;
                
            } else {
                $this->addToBody($this->buffer);
                $this->buffer = '';
                $this->chunkLenRemaining -= $bufferLen;
                
                goto more_data_needed;
            }
        }
        
        more_data_needed: {
            return FALSE;
        }
    }
    
    private function parseTrailers($trailers) {
        $trailerHeaders = http_parse_headers($trailers);
        $ucKeyTrailerHeaders = array_change_key_case($trailerHeaders, CASE_UPPER);
        $ucKeyHeaders = array_change_key_case($this->headers, CASE_UPPER);
        
        unset(
            $ucKeyTrailerHeaders['TRANSFER-ENCODING'],
            $ucKeyTrailerHeaders['CONTENT-LENGTH'],
            $ucKeyTrailerHeaders['TRAILER']
        );
        
        foreach (array_keys($this->headers) as $key) {
            $ucKey = strtoupper($key);
            if (isset($ucKeyTrailerHeaders[$ucKey])) {
                $value = $ucKeyTrailerHeaders[$ucKey];
                $value = ($value === (array) $value) ? $value : [$value];
                $this->headers[$key] = $value;
            }
        }
        
        foreach (array_keys($trailerHeaders) as $key) {
            $ucKey = strtoupper($key);
            if (!isset($ucKeyHeaders[$ucKey])) {
                $value = $trailerHeaders[$key];
                $value = ($value === (array) $value) ? $value : [$value];
                $this->headers[$key] = $value;
            }
        }
    }
    
    function getParsedMessageArray() {
        if ($this->body) {
            rewind($this->body);
        }
        
        $result = [
            'protocol' => $this->protocol,
            'headers'  => $this->headers,
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
        
        if ($this->maxBodyBytes > 0 && $this->bodyBytesConsumed > $this->maxBodyBytes) {
            throw new PolicyException(
                $this->getParsedMessageArray(),
                $msg = "Maximum allowable body size exceeded: {$this->maxBodyBytes}",
                $code = 413,
                $previousException = NULL
            );
        }
        
        if ($onBodyData = $this->onBodyData) {
            $onBodyData($data);
        }
        
        if ($this->storeBody) {
            fwrite($this->body, $data);
        }
    }
    
}

