<?php

namespace Aerys\Parsing;

use Aerys\Status;

class MessageParser {
    
    const MODE_REQUEST = 0;
    const MODE_RESPONSE = 1;
    
    const START = 0;
    const BODY_IDENTITY = 300;
    const BODY_IDENTITY_EOF = 400;
    const BODY_CHUNKS_SIZE_START = 500;
    const BODY_CHUNKS_SIZE = 510;
    const BODY_CHUNKS_SIZE_ALMOST_DONE = 520;
    const BODY_CHUNKS_DATA = 530;
    const BODY_CHUNKS_DATA_TERMINATOR = 540;
    const BODY_CHUNKS_ALMOST_DONE = 550;
    const TRAILER_START = 600;
    
    const STATUS_LINE_PATTERN = "#^
        HTTP/(?P<protocol>\d+\.\d+)[\x20\x09]+
        (?P<status>[1-5]\d\d)[\x20\x09]*
        (?P<reason>[^\x01-\x08\x10-\x19]*)
    $#ix";
    
    const HEADERS_PATTERN = "/
        (?P<field>[^\(\)<>@,;:\\\"\/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+):[\x20\x09]*
        (?P<value>[^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    /x";
    
    protected $mode;
    protected $state = self::START;
    protected $buffer = '';
    protected $traceBuffer;
    protected $protocol;
    protected $requestMethod;
    protected $requestUri;
    protected $responseCode;
    protected $responseReason;
    protected $headers = [];
    protected $body;
    
    protected $remainingBodyBytes;
    protected $currentChunkSize;
    protected $bodyBytesConsumed = 0;
    
    protected $maxStartLineBytes = 2048;
    protected $maxHeaderBytes = 8192;
    protected $maxBodyBytes = 10485760;
    protected $bodySwapSize = 2097152;
    protected $returnHeadersBeforeBody = FALSE;
    protected $hexCharMap = [
        'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1,
        'A' => 1, 'B' => 1, 'C' => 1, 'D' => 1, 'E' => 1, 'F' => 1,
        0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1
    ];
    
    function __construct($mode = self::MODE_REQUEST) {
        $this->mode = $mode;
    }
    
    function setMaxStartLineBytes($bytes) {
        $this->maxStartLineBytes = (int) $bytes;
    }
    
    function setMaxHeaderBytes($bytes) {
        $this->maxHeaderBytes = (int) $bytes;
    }
    
    function setMaxBodyBytes($bytes) {
        $this->maxBodyBytes = (int) $bytes;
    }
    
    function setBodySwapSize($bytes) {
        $this->bodySwapSize = (int) $bytes;
    }
    
    function setReturnHeadersBeforeBody($boolFlag) {
        $this->returnHeadersBeforeBody = (bool) $boolFlag;
    }
    
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }
    }
    
    function hasInProgressMessage() {
        return ($this->state || $this->buffer || $this->buffer === '0');
    }
    
    function hasBuffer() {
        return trim($this->buffer) || $this->buffer === '0';
    }
    
    function parse($data) {
        $this->buffer .= $data;
        
        switch ($this->state) {
            case self::START:
                goto start;
            case self::BODY_IDENTITY:
                goto body_identity;
            case self::BODY_IDENTITY_EOF:
                goto body_identity_eof;
            case self::BODY_CHUNKS_SIZE_START:
                goto body_chunks;
            case self::BODY_CHUNKS_SIZE:
                goto body_chunks;
            case self::BODY_CHUNKS_SIZE_ALMOST_DONE:
                goto body_chunks;
            case self::BODY_CHUNKS_DATA:
                goto body_chunks;
            case self::BODY_CHUNKS_DATA_TERMINATOR:
                goto body_chunks;
            case self::BODY_CHUNKS_ALMOST_DONE:
                goto body_chunks;
            case self::TRAILER_START:
                goto trailer_start;
        }
        
        start: {
            $startLineAndHeaders = $this->shiftHeadersFromMessageBuffer();
            
            if (NULL === $startLineAndHeaders) {
                goto more_data_needed;
            } else {
                $this->parseStartLineAndHeaders($startLineAndHeaders);
            }
            
            $this->traceBuffer = $startLineAndHeaders;
            
            goto transition_from_headers_to_body;
        }
        
        transition_from_headers_to_body: {
            if (!$this->allowsEntityBody()) {
                goto complete;
            } elseif ($this->isChunkEncoded()) {
                $this->state = self::BODY_CHUNKS_SIZE_START;
                goto headers_complete_before_body;
            } elseif ($this->determineBodyLength()) {
                $this->state = self::BODY_IDENTITY;
                goto headers_complete_before_body;
            } elseif ($this->mode === self::MODE_RESPONSE) {
                $this->state = self::BODY_IDENTITY_EOF;
                goto headers_complete_before_body;
            } else {
                goto complete;
            }
        }
        
        headers_complete_before_body: {
            $uri = 'php://temp/maxmemory:' . $this->bodySwapSize;
            $this->body = fopen($uri, 'r+');
            
            if ($this->returnHeadersBeforeBody) {
                $parsedMsgArr = $this->getParsedMessageArray();
                $parsedMsgArr['headersOnly'] = TRUE;
                return $parsedMsgArr;
            } elseif ($this->state == self::BODY_CHUNKS_SIZE_START) {
                goto body_chunks;
            } elseif ($this->state == self::BODY_IDENTITY) {
                goto body_identity;
            } elseif ($this->state == self::BODY_IDENTITY_EOF) {
                goto body_identity_eof;
            }
        }
        
        body_identity: {
            if ($this->bodyIdentity()) {
                goto complete;
            } else {
                goto more_data_needed;
            }
        }
        
        body_identity_eof: {
            $this->bodyIdentityEof();
            goto more_data_needed;
        }
        
        body_chunks: {
            if ($this->bodyChunks()) {
                goto complete;
            } else {
                goto more_data_needed;
            }
        }
        
        trailer_start: {
            // @TODO You mean you want `Trailer:` support?
            goto complete;
        }
        
        complete: {
            $parsedMsgArr = $this->getParsedMessageArray();
            $parsedMsgArr['headersOnly'] = FALSE;
            
            $this->resetForNextMessage();
            
            return $parsedMsgArr;
        }
        
        more_data_needed: {
            return NULL;
        }
    }
    
    protected function shiftHeadersFromMessageBuffer() {
        $this->buffer = ltrim($this->buffer);
        
        if ($headersSize = strpos($this->buffer, "\r\n\r\n")) {
            $terminatorSize = 4;
            $headers = substr($this->buffer, 0, $headersSize + 2);
        } elseif ($headersSize = strpos($this->buffer, "\n\n")) {
            $terminatorSize = 2;
            $headers = substr($this->buffer, 0, $headersSize + 1);
        } else {
            $headersSize = strlen($this->buffer);
            $headers = NULL;
        }
        
        if ($headersSize > $this->maxHeaderBytes) {
            throw new ParseException(NULL, Status::REQUEST_HEADER_FIELDS_TOO_LARGE);
        } elseif ($headers !== NULL) {
            $this->buffer = substr($this->buffer, $headersSize + $terminatorSize);
        }
        
        return $headers;
    }
    
    protected function parseStartLineAndHeaders($startLineAndHeaders) {
        $startLineEndPos = strpos($startLineAndHeaders, "\n");
        $startLine = substr($startLineAndHeaders, 0, $startLineEndPos);
        
        if ($this->mode === self::MODE_REQUEST) {
            $this->parseRequestLine($startLine);
        } else {
            $this->parseStatusLine($startLine);
        }
        
        if ($rawHeaders = substr($startLineAndHeaders, $startLineEndPos + 1)) {
            $this->parseHeaders($rawHeaders);
        }
    }
    
    protected function parseRequestLine($rawStartLine) {
        $parts = explode(' ', trim($rawStartLine));
        
        if (isset($parts[0]) && ($method = trim($parts[0]))) {
            $this->requestMethod = $method;
        } else {
            throw new ParseException(NULL, Status::BAD_REQUEST);
        }
        
        if (isset($parts[1]) && ($uri = trim($parts[1]))) {
            $this->requestUri = $uri;
        } else {
            throw new ParseException(NULL, Status::BAD_REQUEST);
        }
        
        if (isset($parts[2]) && ($protocol = str_ireplace('HTTP/', '', trim($parts[2])))) {
            $this->protocol = $protocol;
        } else {
            throw new ParseException(NULL, Status::BAD_REQUEST);
        }
        
        if (!($protocol === '1.0' || '1.1' === $protocol)) {
            throw new ParseException(NULL, Status::HTTP_VERSION_NOT_SUPPORTED);
        }
    }
    
    protected function parseStatusLine($rawStartLine) {
        if (preg_match(self::STATUS_LINE_PATTERN, $rawStartLine, $m)) {
            $this->protocol = $m['protocol'];
            $this->responseCode = $m['status'];
            $this->responseReason = $m['reason'];
        } else {
            throw new ParseException(NULL, Status::BAD_REQUEST);
        }
    }
    
    protected function parseHeaders($rawHeaders) {
        if (strpos($rawHeaders, "\n\x20") || strpos($rawHeaders, "\n\t")) {
            $rawHeaders = preg_replace("/(?:\r\n|\n)[\x20\t]+/", ' ', $rawHeaders);
        }
        
        if (!preg_match_all(self::HEADERS_PATTERN, $rawHeaders, $matches)) {
            throw new ParseException(NULL, Status::BAD_REQUEST);
        }
        
        $headers = [];
        
        $matchedHeaders = '';
        
        for ($i=0, $c=count($matches[0]); $i<$c; $i++) {
            $matchedHeaders .= $matches[0][$i];
            $field = strtoupper($matches['field'][$i]);
            $value = $matches['value'][$i];
            $headers[$field][] = $value;
        }
        
        if (strlen($rawHeaders) !== strlen($matchedHeaders)) {
            throw new ParseException(NULL, Status::BAD_REQUEST);
        }
        
        $this->headers = $headers;
    }
    
    protected function allowsEntityBody() {
        if ($this->mode === self::MODE_REQUEST) {
            $allowsEntityBody = !($this->requestMethod == 'HEAD' || $this->requestMethod == 'TRACE');
        } else {
            $allowsEntityBody = !($this->responseCode == 204
                || $this->responseCode == 304
                || $this->responseCode < 200
            );
        }
        
        return $allowsEntityBody;
    }
    
    protected function getParsedMessageArray() {
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
    
    protected function isChunkEncoded() {
        return isset($this->headers['TRANSFER-ENCODING'][0])
            ? strcasecmp($this->headers['TRANSFER-ENCODING'][0], 'identity')
            : FALSE;
    }
    
    protected function determineBodyLength() {
        return empty($this->headers['CONTENT-LENGTH'][0])
            ? FALSE
            : (bool) $this->remainingBodyBytes = (int) $this->headers['CONTENT-LENGTH'][0];
    }
    
    protected function bodyIdentity() {
        $bufferDataSize = strlen($this->buffer);
        
        if ($bufferDataSize < $this->remainingBodyBytes) {
            $this->addToBody($this->buffer);
            $this->buffer = NULL;
            $this->remainingBodyBytes -= $bufferDataSize;
            
            return FALSE;
            
        } elseif ($bufferDataSize == $this->remainingBodyBytes) {
            $this->addToBody($this->buffer);
            $this->buffer = NULL;
            $this->remainingBodyBytes = 0;
            
            return TRUE;
            
        } else {
            $bodyData = substr($this->buffer, 0, $this->remainingBodyBytes);
            $this->addToBody($bodyData);
            $this->buffer = substr($this->buffer, $this->remainingBodyBytes);
            $this->remainingBodyBytes = 0;
            
            return TRUE;
            
        }
    }
    
    protected function bodyIdentityEof() {
        $this->addToBody($this->buffer);
        $this->buffer = NULL;
    }
    
    protected function bodyChunks() {
        $parsedBody = '';
        $availableCharCount = strlen($this->buffer);
        
        $i = 0;
        while ($i < $availableCharCount) {
            
            switch ($this->state) {
                case self::BODY_CHUNKS_SIZE_START:
                    $this->currentChunkSize = '';
                    $c = $this->buffer[$i];
                    
                    if (isset($this->hexCharMap[$c])) {
                        $this->currentChunkSize .= $c;
                        $this->state = self::BODY_CHUNKS_SIZE;
                    } else {
                        throw new ParseException('Expected [HEX] value', Status::BAD_REQUEST);
                    }
                    
                    ++$i;
                    break;
                    
                case self::BODY_CHUNKS_SIZE:
                    $c = $this->buffer[$i];
                    
                    if (isset($this->hexCharMap[$c])) {
                        $this->currentChunkSize .= $c;
                    } elseif ($c === "\r") {
                        $this->state = self::BODY_CHUNKS_SIZE_ALMOST_DONE;
                    } else {
                        throw new ParseException('Expected [CR] chunk terminator', Status::BAD_REQUEST);
                    }
                    
                    ++$i;
                    break;
                    
                case self::BODY_CHUNKS_SIZE_ALMOST_DONE:
                    $c = $this->buffer[$i];
                    
                    if ($c === "\n") {
                        $this->currentChunkSize = hexdec($this->currentChunkSize);
                        $this->remainingBodyBytes = $this->currentChunkSize;
                        
                        if ($this->remainingBodyBytes === 0) {
                            $this->state = self::TRAILER_START;
                            break 2;
                        } else {
                            $this->state = self::BODY_CHUNKS_DATA;
                        }
                    } else {
                        throw new ParseException('Expected [LF] chunk terminator', Status::BAD_REQUEST);
                    }
                    
                    ++$i;
                    break;
                    
                case self::BODY_CHUNKS_DATA:
                    $bytesRemainingInBuffer = $availableCharCount - $i;
                    
                    if ($this->remainingBodyBytes <= $bytesRemainingInBuffer) {
                        $parsedBody .= substr($this->buffer, $i, $this->remainingBodyBytes);
                        $this->state = self::BODY_CHUNKS_DATA_TERMINATOR;
                        $i += $this->remainingBodyBytes;
                        $this->remainingBodyBytes = 0;
                        
                        break;
                    } else {
                        $parsedBody .= substr($this->buffer, $i);
                        $i += $bytesRemainingInBuffer;
                        $this->remainingBodyBytes -= $bytesRemainingInBuffer;
                        
                        break 2;
                    }
                    
                case self::BODY_CHUNKS_DATA_TERMINATOR:
                    if ($this->buffer[$i] === "\r") {
                        $this->state = self::BODY_CHUNKS_ALMOST_DONE;
                    } else {
                        throw new ParseException('Expected [CR] chunk terminator', Status::BAD_REQUEST);
                    }
                    
                    ++$i;
                    break;
                
                case self::BODY_CHUNKS_ALMOST_DONE:
                    if ($this->buffer[$i] === "\n") {
                        $this->currentChunkSize = NULL;
                        $this->state = self::BODY_CHUNKS_SIZE_START;
                    } else {
                        throw new ParseException('Expected [LF] chunk terminator', Status::BAD_REQUEST);
                    }
                    
                    ++$i;
                    break;
                
            }
        }
        
        if ($parsedBody !== '') {
            $this->addToBody($parsedBody);
        }
        
        $this->buffer = ($i === $availableCharCount) ? NULL : substr($this->buffer, $i);
        
        return ($this->state === self::TRAILER_START);
    }
    
    protected function addToBody($data) {
        $this->bodyBytesConsumed += strlen($data);
        
        if ($this->maxBodyBytes && $this->bodyBytesConsumed > $this->maxBodyBytes) {
            throw new ParseException(NULL, Status::REQUEST_ENTITY_TOO_LARGE);
        } else {
            fseek($this->body, 0, SEEK_END);
            fwrite($this->body, $data);
        }
    }
    
    protected function resetForNextMessage() {
        $this->state = self::START;
        $this->traceBuffer = NULL;
        $this->headers = [];
        $this->body = NULL;
        $this->bodyBytesConsumed = 0;
        $this->remainingBodyBytes = NULL;
        $this->currentChunkSize = NULL;
        $this->protocol = NULL;
        $this->requestUri = NULL;
        $this->requestMethod = NULL;
        $this->responseCode = NULL;
        $this->responseReason = NULL;
    }
    
}

