<?php

namespace Aerys\Http;

abstract class MessageParser {
    
    const SP = "\x20";
    const CR = "\x0D";
    const LF = "\x0A";
    const CRLF = "\x0D\x0A";
    const CRLFx2 = "\x0D\x0A\x0D\x0A";
    const LFx2 = "\x0A\x0A";
    
    const HEADERS_PATTERN = "/
        (?P<field>[^\(\)<>@,;:\\\"\/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+):[\x20\x09]*
        (?P<value>[^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    /x";
    
    const START_LINE = 0;
    const HEADERS_START = 100;
    const HEADERS = 200;
    const BODY_IDENTITY = 300;
    const BODY_IDENTITY_EOF = 400;
    const BODY_CHUNKS_SIZE_START = 500;
    const BODY_CHUNKS_SIZE = 510;
    const BODY_CHUNKS_SIZE_ALMOST_DONE = 520;
    const BODY_CHUNKS_DATA = 530;
    const BODY_CHUNKS_DATA_TERMINATOR = 540;
    const BODY_CHUNKS_ALMOST_DONE = 550;
    const TRAILER_START = 600;
    const AWAITING_ENTITY_DELEGATE = 700;
    
    const E_START_LINE_SYNTAX = 1000;
    const E_START_LINE_TOO_LARGE = 1010;
    const E_HEADERS_SYNTAX = 1100;
    const E_HEADERS_TOO_LARGE = 1110;
    const E_ENTITY_TOO_LARGE = 1170;
    const E_CHUNKS = 1200;
    const E_TRAILERS = 1300;
    
    const IGNORE_BODY = 'attrIgnoreBody';
    const MAX_START_LINE_BYTES = 'attrMaxStartLineBytes';
    const MAX_HEADER_BYTES = 'attrMaxHeaderBytes';
    const MAX_BODY_BYTES = 'attrMaxBodyBytes';
    
    private $attributes = array(
        self::IGNORE_BODY => FALSE,
        self::MAX_START_LINE_BYTES => 2048,
        self::MAX_HEADER_BYTES => 8192,
        self::MAX_BODY_BYTES => 2097152
    );
    
    private $state = self::START_LINE;
    private $buffer = '';
    private $headers = array();
    private $body = '';
    
    private $remainingBodyBytes;
    private $currentChunkSize;
    private $bodyBytesConsumed = 0;
    
    private $onHeaders;
    private $onBodyData;
    
    private $awaitingEntityDelegate = FALSE;
    
    private $hexCharMap = array(
        'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1,
        'A' => 1, 'B' => 1, 'C' => 1, 'D' => 1, 'E' => 1, 'F' => 1,
        0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1
    );
    
    /**
     * Assign multiple parser attributes at once
     * 
     * @param array $array A key-value traversable mapping attributes to integer values
     * @return MessageParser Returns the current object instance
     */
    function setAllAttributes(array $opts) {
        foreach ($opts as $attribute => $value) {
            if (isset($this->attributes[$attribute])) {
                $this->attributes[$attribute] = (int) $value;
            }
        }
        
        return $this;
    }
    
    /**
     * Assign an optional parser attribute
     * 
     * @param string $attribute
     * @param int $value
     * @throws \Ardent\KeyException On invalid attribute name
     * @return MessageParser Returns the current object instance
     */
    function setAttribute($attribute, $value) {
        if (isset($this->attributes[$attribute])) {
            $this->attributes[$attribute] = (int) $value;
        } else {
            throw new KeyException(
                'Invalid attribute'
            );
        }
        
        return $this;
    }
    
    function onHeaders($callback) {
        if ($callback === NULL || is_callable($callback)) {
            $this->onHeaders = $callback;
        } else {
            throw new FunctionException;
        }
    }
    
    function onBodyData($callback) {
        if ($callback === NULL || is_callable($callback)) {
            $this->onBodyData = $callback;
        } else {
            throw new FunctionException;
        }
    }
    
    abstract protected function parseStartLine($rawStartLine);
    abstract protected function allowsEntityBody();
    abstract protected function getParsedMessageVals();
    
    function parse($data) {
        $this->buffer .= $data;
        
        switch ($this->state) {
            case self::START_LINE:
                goto start_line;
            case self::HEADERS_START:
                goto headers_start;
            case self::HEADERS:
                goto headers;
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
            case self::AWAITING_ENTITY_DELEGATE:
                goto awaiting_entity_delegate_completion;
        }
        
        start_line: {
            $startLine = $this->shiftStartLineFromMessageBuffer();
            
            if (NULL !== $startLine) {
                $this->parseStartLine($startLine);
                $this->state = self::HEADERS_START;
                goto headers_start;
            } else {
                goto more_data_needed;
            }
        }
        
        headers_start: {
            if ($this->buffer === '' || $this->buffer === FALSE) {
                goto more_data_needed;
            } elseif ($this->buffer[0] == self::LF) {
                $this->buffer = substr($this->buffer, 1);
                goto complete;
            } elseif (substr($this->buffer, 0, 2) == self::CRLF) {
                $this->buffer = substr($this->buffer, 2);
                goto complete;
            } else {
                $this->state = self::HEADERS;
                goto headers;
            }
        }
        
        headers: {
            $headers = $this->shiftHeadersFromMessageBuffer();
            
            if (NULL !== $headers) {
                $this->parseHeaders($headers);
                goto transition_from_headers_to_body;
            } else {
                goto more_data_needed;
            }
        }
        
        transition_from_headers_to_body: {
            if ($this->attributes[self::IGNORE_BODY]) {
                goto complete;
            } elseif (!$this->allowsEntityBody()) {
                goto complete;
            } elseif ($this->isChunkEncoded()) {
                if ($this->onHeaders && !isset($this->headers['TRAILER'])) {
                    $parsedMsgArr = $this->getParsedMessageVals();
                    $callback = $this->onHeaders;
                    $callback($parsedMsgArr);
                }
                $this->state = self::BODY_CHUNKS_SIZE_START;
                goto body_chunks;
            } elseif ($this->determineBodyLength()) {
                if ($this->onHeaders && !isset($this->headers['TRAILER'])) {
                    $parsedMsgArr = $this->getParsedMessageVals();
                    $callback = $this->onHeaders;
                    $callback($parsedMsgArr);
                }
                $this->state = self::BODY_IDENTITY;
                goto body_identity;
            } else {
                // @todo read until connection is closed for requests
                goto complete;
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
            goto complete;
        }
        
        awaiting_entity_delegate_completion: {
            $this->state = self::AWAITING_ENTITY_DELEGATE;
            $callback = $this->onBodyData;
            if ($this->awaitingEntityDelegate = $callback()) {
                return NULL;
            } else {
                $this->onBodyData = NULL;
                goto complete;
            }
        }
        
        complete: {
            if ($this->awaitingEntityDelegate) {
                goto awaiting_entity_delegate_completion;
            }
            
            $parsedMsgArr = $this->getParsedMessageVals();
            $this->resetForNextMessage();
            
            return $parsedMsgArr;
        }
        
        more_data_needed: {
            return NULL;
        }
    }
    
    private function shiftStartLineFromMessageBuffer() {
        $this->buffer = ltrim($this->buffer);
        
        if ($startLineSize = strpos($this->buffer, self::LF)) {
            $startLine = substr($this->buffer, 0, $startLineSize + 1);
            $this->buffer = substr($this->buffer, $startLineSize + 1);
        } else {
            $startLineSize = strlen($this->buffer);
            $startLine = NULL;
        }
        
        if ($startLineSize > $this->attributes[self::MAX_START_LINE_BYTES]) {
            throw new ParseException(
                "Maximum allowable start line size exceeded",
                self::E_START_LINE_TOO_LARGE
            );
        }
        
        return $startLine;
    }
    
    private function shiftHeadersFromMessageBuffer() {
        if ($headersSize = strpos($this->buffer, self::CRLFx2)) {
            $terminatorSize = 4;
            $headers = substr($this->buffer, 0, $headersSize + 2);
        } elseif ($headersSize = strpos($this->buffer, self::LFx2)) {
            $terminatorSize = 2;
            $headers = substr($this->buffer, 0, $headersSize + 1);
        } else {
            $headersSize = strlen($this->buffer);
            $headers = NULL;
        }
        
        if ($headersSize > $this->attributes[self::MAX_HEADER_BYTES]) {
            throw new ParseException(
                "Maximum allowable headers size exceeded",
                self::E_HEADERS_TOO_LARGE
            );
        }
        
        if ($headers !== NULL) {
            $this->buffer = substr($this->buffer, $headersSize + $terminatorSize);
        }
        
        return $headers;
    }
    
    private function parseHeaders($headers) {
        if (strpos($headers, "\n\x20") || strpos($headers, "\n\x09")) {
            $headers = preg_replace("/(?:\r\n|\n)[\x20\x09]+/", self::SP, $headers);
        }
        
        if (!preg_match_all(self::HEADERS_PATTERN, $headers, $matches)) {
            throw new ParseException(
                "Invalid headers",
                self::E_HEADERS_SYNTAX
            );
        }
        
        $matchedHeaders = '';
        for ($i=0, $c=count($matches[0]); $i<$c; $i++) {
            $matchedHeaders .= $matches[0][$i];
            $field = strtoupper($matches['field'][$i]);
            $value = $matches['value'][$i];
            
            if (isset($this->headers[$field])) {
                $this->headers[$field][] = $value;
            } else {
                $this->headers[$field] = array($value);
            }
        }
        
        if (strlen($headers) !== strlen($matchedHeaders)) {
            throw new ParseException(
                "Invalid headers",
                self::E_HEADERS_SYNTAX
            );
        }
    }
    
    private function isChunkEncoded() {
        return !isset($this->headers['TRANSFER-ENCODING'][0])
            ? FALSE
            : strcasecmp($this->headers['TRANSFER-ENCODING'][0], 'identity');
    }
    
    private function determineBodyLength() {
        if (empty($this->headers['CONTENT-LENGTH'][0])) {
            return FALSE;
        } else {
            return (bool) $this->remainingBodyBytes = (int) $this->headers['CONTENT-LENGTH'][0];
        }
    }
    
    private function bodyIdentity() {
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
            $overflowBytes = $bufferDataSize - $this->remainingBodyBytes;
            $bodyData = substr($this->buffer, 0, $this->remainingBodyBytes);
            $this->addToBody($this->buffer);
            $this->buffer = substr($this->buffer, $overflowBytes);
            
            return TRUE;
            
        }
    }
    
    private function bodyIdentityEof() {
        $this->addToBody($this->buffer);
        $this->buffer = NULL;
    }
    
    private function bodyChunks() {
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
                        throw new ParseException(
                            'Expected [HEX] value',
                            self::E_CHUNKS
                        );
                    }
                    
                    ++$i;
                    break;
                    
                case self::BODY_CHUNKS_SIZE:
                    $c = $this->buffer[$i];
                    
                    if (isset($this->hexCharMap[$c])) {
                        $this->currentChunkSize .= $c;
                    } elseif ($c === self::CR) {
                        $this->state = self::BODY_CHUNKS_SIZE_ALMOST_DONE;
                    } else {
                        throw new ParseException(
                            'Expected [CR] chunk terminator',
                            self::E_CHUNKS
                        );
                    }
                    
                    ++$i;
                    break;
                    
                case self::BODY_CHUNKS_SIZE_ALMOST_DONE:
                    $c = $this->buffer[$i];
                    
                    if ($c === self::LF) {
                        $this->currentChunkSize = hexdec($this->currentChunkSize);
                        $this->remainingBodyBytes = $this->currentChunkSize;
                        
                        if ($this->remainingBodyBytes === 0) {
                            $this->state = self::TRAILER_START;
                            break 2;
                        } else {
                            $this->state = self::BODY_CHUNKS_DATA;
                        }
                    } else {
                        throw new ParseException(
                            'Expected [LF] chunk terminator',
                            self::E_CHUNKS
                        );
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
                    if ($this->buffer[$i] === self::CR) {
                        $this->state = self::BODY_CHUNKS_ALMOST_DONE;
                    } else {
                        throw new ParseException(
                            'Expected [CR] chunk terminator',
                            self::E_CHUNKS
                        );
                    }
                    
                    ++$i;
                    break;
                
                case self::BODY_CHUNKS_ALMOST_DONE:
                    if ($this->buffer[$i] === self::LF) {
                        $this->currentChunkSize = NULL;
                        $this->state = self::BODY_CHUNKS_SIZE_START;
                    } else {
                        throw new ParseException(
                            'Expected [LF] chunk terminator',
                            self::E_CHUNKS
                        );
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
    
    private function addToBody($data) {
        $this->bodyBytesConsumed += strlen($data);
        $maxSize = $this->attributes[self::MAX_BODY_BYTES];
        
        if ($maxSize && $this->bodyBytesConsumed > $maxSize) {
            throw new ParseException(
                'Entity body too large',
                self::E_ENTITY_TOO_LARGE
            );
        } elseif ($this->onBodyData) {
            $callback = $this->onBodyData;
            $this->awaitingEntityDelegate = !$callback($data);
        } else {
            $this->body .= $data;
        }
    }
    
    function getHeaders() {
        $headers = array();
        foreach ($this->headers as $key => $arr) {
            $headers[$key] = (count($arr) == 1) ? $arr[0] : $arr;
        }
        
        return $headers;
    }
    
    function getBody() {
        return $this->body;
    }
    
    protected function resetForNextMessage() {
        $this->state = self::START_LINE;
        $this->headers = array();
        $this->body = NULL;
        $this->bodyBytesConsumed = 0;
        $this->remainingBodyBytes = NULL;
        $this->currentChunkSize = NULL;
    }
    
}

