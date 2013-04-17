<?php

namespace Aerys\Parsing;

class MessageParser {
    
    const MODE_REQUEST = 0;
    const MODE_RESPONSE = 1;
    
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
    
    const STATUS_LINE_PATTERN = "#^
        HTTP/(?P<protocol>\d+\.\d+)[\x20\x09]+
        (?P<status>[1-5]\d\d)[\x20\x09]*
        (?P<reason>[^\x01-\x08\x10-\x19]*)
    $#ix";
    
    const HEADERS_PATTERN = "/
        (?P<field>[^\(\)<>@,;:\\\"\/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+):[\x20\x09]*
        (?P<value>[^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    /x";
    
    protected $input;
    protected $mode;
    protected $state = self::START_LINE;
    protected $onHeadersCallback;
    protected $onBodyCallback;
    
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
    protected $awaitingEntityDelegate = FALSE;
    
    protected $maxStartLineBytes = 2048;
    protected $maxHeaderBytes = 8192;
    protected $maxBodyBytes = 2097152;
    protected $hexCharMap = [
        'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1,
        'A' => 1, 'B' => 1, 'C' => 1, 'D' => 1, 'E' => 1, 'F' => 1,
        0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1
    ];
    
    function __construct($inputStream, $mode = self::MODE_REQUEST) {
        $this->inputStream = $inputStream;
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
    
    function setOnHeadersCallback(callable $callback) {
        $this->onHeadersCallback = $callback;
    }
    
    function setOnBodyCallback(callable $callback) {
        $this->onBodyCallback = $callback;
    }
    
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }
    }
    
    function hasInProgressMessage() {
        return ($this->state || $this->buffer || $this->buffer === '0');
    }
    
    function parse() {
        $data = @fread($this->inputStream, 8192);
        
        if (!$data
            && $data !== '0'
            && $this->buffer === ''
            && (!is_resource($this->inputStream) || feof($this->inputStream))
        ) {
            throw new ResourceReadException(
                'Failed reading from input stream'
            );
        } else {
            $this->buffer .= $data;
        }
        
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
            
            if (NULL === $startLine) {
                goto more_data_needed;
            } elseif ($this->mode === self::MODE_REQUEST) {
                $this->parseRequestLine($startLine);
            } else {
                $this->parseStatusLine($startLine);
            }
            
            $this->state = self::HEADERS_START;
            $this->traceBuffer = $startLine;
            
            goto headers_start;
        }
        
        headers_start: {
            if ($this->buffer === '' || $this->buffer === FALSE) {
                goto more_data_needed;
            } elseif ($this->buffer[0] == "\n") {
                $this->traceBuffer .= "\n";
                $this->buffer = substr($this->buffer, 1);
                goto complete;
            } elseif (substr($this->buffer, 0, 2) == "\r\n") {
                $this->traceBuffer .= "\r\n";
                $this->buffer = substr($this->buffer, 2);
                goto complete;
            } else {
                $this->state = self::HEADERS;
                goto headers;
            }
        }
        
        headers: {
            $rawHeaders = $this->shiftHeadersFromMessageBuffer();
            
            if (NULL !== $rawHeaders) {
                $this->traceBuffer .= $rawHeaders;
                $this->headers = $this->parseHeaders($rawHeaders);
                goto transition_from_headers_to_body;
            } else {
                goto more_data_needed;
            }
        }
        
        transition_from_headers_to_body: {
            if (!$this->allowsEntityBody()) {
                goto complete;
            } elseif ($this->isChunkEncoded()) {
                if ($this->onHeadersCallback) {
                    $parsedMsgArr = $this->getParsedMessageArray();
                    $callback = $this->onHeadersCallback;
                    $callback($parsedMsgArr);
                }
                $this->state = self::BODY_CHUNKS_SIZE_START;
                goto body_chunks;
            } elseif ($this->determineBodyLength()) {
                if ($this->onHeadersCallback) {
                    $parsedMsgArr = $this->getParsedMessageArray();
                    $callback = $this->onHeadersCallback;
                    $callback($parsedMsgArr);
                }
                $this->state = self::BODY_IDENTITY;
                goto body_identity;
            } elseif ($this->mode === self::MODE_RESPONSE) {
                goto body_identity_eof;
            } else {
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
            $callback = $this->onBodyCallback;
            if ($this->awaitingEntityDelegate = $callback()) {
                return NULL;
            } else {
                $this->onBodyCallback = NULL;
                goto complete;
            }
        }
        
        complete: {
            if ($this->awaitingEntityDelegate) {
                goto awaiting_entity_delegate_completion;
            }
            
            $parsedMsgArr = $this->getParsedMessageArray();
            
            $this->resetForNextMessage();
            
            return $parsedMsgArr;
        }
        
        more_data_needed: {
            return NULL;
        }
    }
    
    protected function shiftStartLineFromMessageBuffer() {
        $this->buffer = ltrim($this->buffer);
        
        if ($startLineSize = strpos($this->buffer, "\n")) {
            $startLine = substr($this->buffer, 0, $startLineSize + 1);
            $this->buffer = substr($this->buffer, $startLineSize + 1);
        } else {
            $startLineSize = strlen($this->buffer);
            $startLine = NULL;
        }
        
        if ($startLineSize > $this->maxStartLineBytes) {
            throw new StartLineSizeException;
        }
        
        return $startLine;
    }
    
    protected function shiftHeadersFromMessageBuffer() {
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
            throw new HeaderSizeException;
        }
        
        if ($headers !== NULL) {
            $this->buffer = substr($this->buffer, $headersSize + $terminatorSize);
        }
        
        return $headers;
    }
    
    protected function parseRequestLine($rawStartLine) {
        $parts = explode(' ', trim($rawStartLine));
        
        if (isset($parts[0]) && ($method = trim($parts[0]))) {
            $this->requestMethod = $method;
        } else {
            throw new StartLineSyntaxException;
        }
        
        if (isset($parts[1]) && ($uri = trim($parts[1]))) {
            $this->requestUri = $uri;
        } else {
            throw new StartLineSyntaxException;
        }
        
        if (isset($parts[2]) && ($protocol = str_ireplace('HTTP/', '', trim($parts[2])))) {
            $this->protocol = $protocol;
        } else {
            throw new StartLineSyntaxException;
        }
        
        if (!($protocol === '1.0' || '1.1' === $protocol)) {
            throw new ProtocolNotSupportedException;
        }
    }
    
    protected function parseStatusLine($rawStartLine) {
        if (preg_match(self::STATUS_LINE_PATTERN, $rawStartLine, $m)) {
            $this->protocol = $m['protocol'];
            $this->responseCode = $m['status'];
            $this->responseReason = $m['reason'];
        } else {
            throw new StartLineSyntaxException;
        }
    }
    
    protected function parseHeaders($rawHeaders) {
        if (strpos($rawHeaders, "\n\x20") || strpos($rawHeaders, "\n\t")) {
            $rawHeaders = preg_replace("/(?:\r\n|\n)[\x20\t]+/", ' ', $rawHeaders);
        }
        
        if (!preg_match_all(self::HEADERS_PATTERN, $rawHeaders, $matches)) {
            throw new HeaderSyntaxException;
        }
        
        $result = [];
        
        $matchedHeaders = '';
        
        for ($i=0, $c=count($matches[0]); $i<$c; $i++) {
            $matchedHeaders .= $matches[0][$i];
            $field = strtoupper($matches['field'][$i]);
            $value = $matches['value'][$i];
            $result[$field][] = $value;
        }
        
        if (strlen($rawHeaders) !== strlen($matchedHeaders)) {
            throw new HeaderSyntaxException;
        }
        
        return $result;
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
                        throw new ChunkSyntaxException(
                            'Expected [HEX] value'
                        );
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
                        throw new ChunkSyntaxException(
                            'Expected [CR] chunk terminator'
                        );
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
                        throw new ChunkSyntaxException(
                            'Expected [LF] chunk terminator'
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
                    if ($this->buffer[$i] === "\r") {
                        $this->state = self::BODY_CHUNKS_ALMOST_DONE;
                    } else {
                        throw new ChunkSyntaxException(
                            'Expected [CR] chunk terminator'
                        );
                    }
                    
                    ++$i;
                    break;
                
                case self::BODY_CHUNKS_ALMOST_DONE:
                    if ($this->buffer[$i] === "\n") {
                        $this->currentChunkSize = NULL;
                        $this->state = self::BODY_CHUNKS_SIZE_START;
                    } else {
                        throw new ChunkSyntaxException(
                            'Expected [LF] chunk terminator'
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
            throw new EntitySizeException;
        } elseif ($onBodyCallback = $this->onBodyCallback) {
            $this->awaitingEntityDelegate = !$onBodyCallback($data);
        } else {
            $this->body .= $data;
        }
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
        $this->requestUri = NULL;
        $this->requestMethod = NULL;
        $this->responseCode = NULL;
        $this->responseReason = NULL;
    }
    
}

