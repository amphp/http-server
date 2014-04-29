<?php

namespace Aerys;

class Parser {
    const MODE_REQUEST = 1;
    const MODE_RESPONSE = 2;

    const AWAITING_HEADERS = 0;
    const BODY_IDENTITY = 1;
    const BODY_IDENTITY_EOF = 2;
    const BODY_CHUNKS = 3;
    const TRAILERS_START = 4;
    const TRAILERS = 5;

    const STATUS_LINE_PATTERN = "#^
        HTTP/(?P<protocol>\d+\.\d+)[\x20\x09]+
        (?P<status>[1-5]\d\d)[\x20\x09]*
        (?P<reason>[^\x01-\x08\x10-\x19]*)
    $#ix";

    const HEADERS_PATTERN = "/
        ([^\(\)<>@,;:\\\"\/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    /x";

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
    private $chunkLenRemaining = NULL;
    private $responseMethodMatch = [];
    private $parseFlowHeaders = [
        'TRANSFER-ENCODING' => NULL,
        'CONTENT-LENGTH' => NULL
    ];

    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = -1;
    private $storeBody = TRUE;
    private $onBodyData;
    private $returnBeforeEntity = FALSE;

    private static $availableOptions = [
        'maxHeaderBytes' => 1,
        'maxBodyBytes' => 1,
        'storeBody' => 1,
        'onBodyData' => 1,
        'returnBeforeEntity' => 1
    ];

    public function __construct($mode = self::MODE_REQUEST) {
        $this->mode = $mode;
    }

    public function setOptions(array $options) {
        if ($options = array_intersect_key($options, self::$availableOptions)) {
            foreach ($options as $key => $value) {
                $this->{$key} = $value;
            }
        }

        return $this;
    }

    public function enqueueResponseMethodMatch($method) {
        $this->responseMethodMatch[] = $method;
    }

    public function getBuffer() {
        return $this->buffer;
    }

    public function getState() {
        return $this->state;
    }

    public function parse($data) {
        $buffer = $this->buffer .= $data;

        if ($buffer == '') {
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
            $buffer = ltrim($buffer, "\r\n");

            if ($headerPos = strpos($buffer, "\r\n\r\n")) {
                $startLineAndHeaders = substr($buffer, 0, $headerPos + 2);
                $this->buffer = $buffer = (string) substr($buffer, $headerPos + 4);
            } elseif ($headerPos = strpos($buffer, "\n\n")) {
                $startLineAndHeaders = substr($buffer, 0, $headerPos + 1);
                $this->buffer = $buffer = (string) substr($buffer, $headerPos + 2);
            } elseif ($this->maxHeaderBytes > 0 && strlen($this->buffer) > $this->maxHeaderBytes) {
                throw new ParserException(
                    $this->getParsedMessageArray(),
                    $msg = "Maximum allowable header size exceeded: {$this->maxHeaderBytes}",
                    $code = 431
                );
            } else {
                goto more_data_needed;
            }

            goto start_line;
        }

        start_line: {
            $startLineEndPos = strpos($startLineAndHeaders, "\n");
            $startLine = rtrim(substr($startLineAndHeaders, 0, $startLineEndPos), "\r\n");
            $rawHeaders = substr($startLineAndHeaders, $startLineEndPos + 1);
            $this->traceBuffer = $startLineAndHeaders;

            if ($this->mode === self::MODE_REQUEST) {
                goto request_line_and_headers;
            } else {
                goto status_line_and_headers;
            }
        }

        request_line_and_headers: {
            if (!$this->requestMethod = strtok($startLine, " ")) {
                $this->requestMethod = $this->requestUri = $this->protocol = '?';
                throw new ParserException(
                    $this->getParsedMessageArray(),
                    $msg = 'Invalid request line',
                    $code = 400
                );
            }

            if (!$this->requestUri = strtok(" ")) {
                $this->requestUri = $this->protocol = '?';
                throw new ParserException(
                    $this->getParsedMessageArray(),
                    $msg = 'Invalid request line',
                    $code = 400
                );
            }

            $protocol = strtok(" ");
            $protocol = str_ireplace('HTTP/', '', $protocol);
            if ($protocol === '1.1' || $protocol === '1.0') {
                $this->protocol = $protocol;
            } else {
                $this->protocol = '?';
                throw new ParserException(
                    $this->getParsedMessageArray(),
                    $msg = 'Protocol not supported',
                    $code = 505
                );
            }

            if ($rawHeaders) {
                $this->headers = $this->parseHeadersFromRaw($rawHeaders);
            }

            goto transition_from_request_headers_to_body;
        }

        status_line_and_headers: {
            if (preg_match(self::STATUS_LINE_PATTERN, $startLine, $m)) {
                $this->protocol = $m['protocol'];
                $this->responseCode = $m['status'];
                $this->responseReason = $m['reason'];
            } else {
                throw new ParserException(
                    $this->getParsedMessageArray(),
                    $msg = 'Invalid status line',
                    $code = 400,
                    $previousException = NULL
                );
            }

            if ($rawHeaders) {
                $this->headers = $this->parseHeadersFromRaw($rawHeaders);
            }

            goto transition_from_response_headers_to_body;
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

        transition_from_response_headers_to_body: {
            $requestMethod = array_shift($this->responseMethodMatch);

            if ($this->responseCode == 204
                || $this->responseCode == 304
                || $this->responseCode < 200
                || $requestMethod === 'HEAD'
            ) {
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

            if ($this->returnBeforeEntity) {
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
                    goto body_chunks;
                default:
                    throw new \RuntimeException(
                        'Unexpected parse state encountered'
                    );
            }
        }

        body_identity: {
            $bufferDataSize = strlen($this->buffer);

            if ($bufferDataSize < $this->remainingBodyBytes) {
                $this->addToBody($this->buffer);
                $this->buffer = NULL;
                $this->remainingBodyBytes -= $bufferDataSize;
                goto more_data_needed;
            } elseif ($bufferDataSize == $this->remainingBodyBytes) {
                $this->addToBody($this->buffer);
                $this->buffer = NULL;
                $this->remainingBodyBytes = 0;
                goto complete;
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
            throw new ParserException(
                $this->getParsedMessageArray(),
                $msg = "Maximum allowable header size exceeded: {$this->maxHeaderBytes}",
                $code = 431,
                $previousException = NULL
            );
        }

        return $headers;
    }

    private function parseHeadersFromRaw($rawHeaders) {
        if (strpos($rawHeaders, "\n\x20") || strpos($rawHeaders, "\n\t")) {
            $rawHeaders = preg_replace("/(?:\r\n|\n)[\x20\t]+/", ' ', $rawHeaders);
        }

        if (!preg_match_all(self::HEADERS_PATTERN, $rawHeaders, $matches)) {
            throw new ParserException(
                $this->getParsedMessageArray(),
                $msg = 'Invalid headers',
                $code = 400,
                $previousException = NULL
            );
        }

        list($lines, $fields, $values) = $matches;

        $headers = [];
        foreach ($fields as $index => $field) {
            $headers[strtoupper($field)][] = $values[$index];
        }

        if (isset($headers['CONTENT-LENGTH'])) {
            $this->parseFlowHeaders['CONTENT-LENGTH'] = (int) $headers['CONTENT-LENGTH'][0];
        }

        if (isset($headers['TRANSFER_ENCODING'])) {
            $value = $headers['TRANSFER_ENCODING'][0];
            $this->parseFlowHeaders['TRANSFER_ENCODING'] = (bool) strcasecmp($value, 'identity');
        }

        return $headers;
    }

    private function dechunk() {
        if ($this->chunkLenRemaining !== NULL) {
            goto dechunk;
        }

        determine_chunk_size: {
            if (FALSE === ($lineEndPos = strpos($this->buffer, "\r\n"))) {
                goto more_data_needed;
            } elseif ($lineEndPos === 0) {
                throw new ParserException(
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
                throw new ParserException(
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
        $trailerHeaders = $this->parseHeadersFromRaw($trailers);
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
                $this->headers[$key] = $ucKeyTrailerHeaders[$ucKey];
            }
        }

        foreach (array_keys($trailerHeaders) as $key) {
            $ucKey = strtoupper($key);
            if (!isset($ucKeyHeaders[$ucKey])) {
                $this->headers[$key] = $trailerHeaders[$key];
            }
        }
    }

    public function getParsedMessageArray() {
        if ($this->body) {
            rewind($this->body);
        }

        $result = [
            'protocol'    => $this->protocol,
            'headers'     => $this->headers,
            'body'        => $this->body,
            'trace'       => $this->traceBuffer,
            'headersOnly' => FALSE
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
            throw new ParserException(
                $this->getParsedMessageArray(),
                $msg = 'Maximum allowable body size exceeded',
                $code = 413,
                $previousException = NULL
            );
        }

        if ($onBodyData = $this->onBodyData) {
            $onBodyData($data);
        }

        if ($this->storeBody) {
            fseek($this->body, 0, SEEK_END);
            fwrite($this->body, $data);
        }
    }
}
