<?php

namespace Aerys\Http\Io;

use Aerys\Http\HttpServer;

abstract class MessageParser implements \Aerys\Pipeline\Reader {
    
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
    const E_PROTOCOL_NOT_SUPPORTED = 1020;
    const E_HEADERS_SYNTAX = 1100;
    const E_HEADERS_TOO_LARGE = 1110;
    const E_ENTITY_TOO_LARGE = 1170;
    const E_CHUNKS = 1200;
    const E_TRAILERS = 1300;
    
    protected $input;
    protected $state = self::START_LINE;
    protected $buffer = '';
    protected $headers = [];
    protected $body;
    protected $protocol;
    protected $traceBuffer;
    
    protected $remainingBodyBytes;
    protected $currentChunkSize;
    protected $bodyBytesConsumed = 0;
    
    protected $onHeaders;
    protected $onBody;
    
    protected $maxStartLineBytes = 2048;
    protected $maxHeaderBytes = 8192;
    protected $maxBodyBytes = 2097152;
    protected $hexCharMap = [
        'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1,
        'A' => 1, 'B' => 1, 'C' => 1, 'D' => 1, 'E' => 1, 'F' => 1,
        0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1
    ];
    protected $awaitingEntityDelegate = FALSE;
    
    function __construct($inputStream) {
        $this->input = $inputStream;
    }
    
    abstract protected function parseStartLine($rawStartLine);
    abstract protected function allowsEntityBody();
    abstract protected function getParsedMessageVals();
    abstract protected function resetForNextMessage();
    
    function setMaxStartLineBytes($bytes) {
        $this->maxStartLineBytes = (int) $bytes;
    }
    
    function setMaxHeaderBytes($bytes) {
        $this->maxHeaderBytes = (int) $bytes;
    }
    
    function setMaxBodyBytes($bytes) {
        $this->maxBodyBytes = (int) $bytes;
    }
    
    function onHeaders(callable $callback) {
        $this->onHeaders = $callback;
    }
    
    function onBody(callable $callback) {
        $this->onBody = $callback;
    }
    
    function inProgress() {
        return ($this->state || $this->buffer || $this->buffer === '0');
    }
    
    function read() {
        $data = fread($this->input, 8192);
        
        if (!$data && $data !== '0' && (!is_resource($this->input) || feof($this->input))) {
            throw new ResourceException(
                'Failed reading from input stream'
            );
        }
        
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
                $this->traceBuffer = $startLine;
                goto validate_protocol;
            } else {
                goto more_data_needed;
            }
        }
        
        validate_protocol: {
            if ($this->protocol == HttpServer::PROTOCOL_V10
                || $this->protocol == HttpServer::PROTOCOL_V11
            ) {
                goto headers_start;
            } else {
                throw new ParseException(
                    'Protocol not supported',
                    self::E_PROTOCOL_NOT_SUPPORTED
                );
            }
        }
        
        headers_start: {
            if ($this->buffer === '' || $this->buffer === FALSE) {
                goto more_data_needed;
            } elseif ($this->buffer[0] == self::LF) {
                $this->traceBuffer .= self::LF;
                $this->buffer = substr($this->buffer, 1);
                goto complete;
            } elseif (substr($this->buffer, 0, 2) == self::CRLF) {
                $this->traceBuffer .= self::CRLF;
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
                $this->traceBuffer .= $headers;
                $this->parseHeaders($headers);
                goto transition_from_headers_to_body;
            } else {
                goto more_data_needed;
            }
        }
        
        transition_from_headers_to_body: {
            if (!$this->allowsEntityBody()) {
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
                // @TODO Allow reading until connection is closed when parsing responses
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
            // @TODO You mean you want `Trailer:` support?
            goto complete;
        }
        
        awaiting_entity_delegate_completion: {
            $this->state = self::AWAITING_ENTITY_DELEGATE;
            $callback = $this->onBody;
            if ($this->awaitingEntityDelegate = $callback()) {
                return NULL;
            } else {
                $this->onBody = NULL;
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
    
    protected function shiftStartLineFromMessageBuffer() {
        $this->buffer = ltrim($this->buffer);
        
        if ($startLineSize = strpos($this->buffer, self::LF)) {
            $startLine = substr($this->buffer, 0, $startLineSize + 1);
            $this->buffer = substr($this->buffer, $startLineSize + 1);
        } else {
            $startLineSize = strlen($this->buffer);
            $startLine = NULL;
        }
        
        if ($startLineSize > $this->maxStartLineBytes) {
            throw new ParseException(
                "Maximum allowable start line size exceeded",
                self::E_START_LINE_TOO_LARGE
            );
        }
        
        return $startLine;
    }
    
    protected function shiftHeadersFromMessageBuffer() {
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
        
        if ($headersSize > $this->maxHeaderBytes) {
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
    
    protected function parseHeaders($headers) {
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
    
    protected function isChunkEncoded() {
        return !isset($this->headers['TRANSFER-ENCODING'][0])
            ? FALSE
            : strcasecmp($this->headers['TRANSFER-ENCODING'][0], 'identity');
    }
    
    protected function determineBodyLength() {
        if (empty($this->headers['CONTENT-LENGTH'][0])) {
            return FALSE;
        } else {
            return (bool) $this->remainingBodyBytes = (int) $this->headers['CONTENT-LENGTH'][0];
        }
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
    
    protected function addToBody($data) {
        $this->bodyBytesConsumed += strlen($data);
        
        if ($this->maxBodyBytes && $this->bodyBytesConsumed > $this->maxBodyBytes) {
            throw new ParseException(
                'Entity body too large',
                self::E_ENTITY_TOO_LARGE
            );
        } elseif ($onBody = $this->onBody) {
            $this->awaitingEntityDelegate = !$onBody($data);
        } else {
            $this->body .= $data;
        }
    }
    
}

