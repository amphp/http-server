<?php

namespace Aerys;

class Rfc7230RequestParser {
    const ERROR = 1;
    const RESULT = 4;
    const ENTITY_HEADERS = 8;
    const ENTITY_PART = 16;
    const ENTITY_RESULT = 32;

    const S_AWAITING_HEADERS = 0;
    const S_BODY_IDENTITY = 1;
    const S_BODY_CHUNK_SIZE = 2;
    const S_BODY_CHUNKS = 3;
    const S_TRAILERS_START = 4;
    const S_TRAILERS = 5;

    const ENTITY_HEADERS_PATTERN = "/
        ([^\(\)<>@,;:\\\"\/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    /x";

    private $state;
    private $emitCallback;
    private $maxHeaderSize = 32768;
    private $maxBodySize = 131072;
    private $bodyEmitSize = 32768;
    private $callbackData;
    private $buffer;
    private $traceBuffer;
    private $protocol;
    private $headers = [];
    private $method;
    private $uri;
    private $body = "";
    private $hasBody;
    private $isChunked;
    private $contentLength;
    private $bodyBufferSize;
    private $bodyBytesConsumed;
    private $chunkLenRemaining;
    private $remainingBodyBytes;

    public function __construct(callable $emitCallback, array $options = []) {
        $this->state = self::S_AWAITING_HEADERS;
        $this->emitCallback = $emitCallback;
        $this->maxHeaderSize = $options["max_header_size"] ?? 32768;
        $this->maxBodySize = $options["max_body_size"] ?? 131072;
        $this->bodyEmitSize = $options["body_emit_size"] ?? 32768;
        $this->callbackData = $options["callback_data"] ?? null;
    }

    /**
     * 
     */
    public function sink(string $data) {
        $buffer = $this->buffer .= $data;

        if ($buffer == "") {
            goto more_data_needed;
        }

        start: {
            switch ($this->state) {
                case self::S_AWAITING_HEADERS:
                    goto awaiting_headers;
                case self::S_BODY_IDENTITY:
                    goto body_identity;
                case self::S_BODY_CHUNK_SIZE:
                    goto body_chunk_size;
                case self::S_BODY_CHUNKS:
                    goto body_chunks;
                case self::S_TRAILERS_START:
                    goto trailers_start;
                case self::S_TRAILERS:
                    goto trailers;
            }
        }

        awaiting_headers: {
            $buffer = ltrim($buffer, "\r\n");

            if ($headerPos = strpos($buffer, "\r\n\r\n")) {
                $startLineAndHeaders = substr($buffer, 0, $headerPos + 2);
                $this->buffer = $buffer = (string) substr($buffer, $headerPos + 4);
                goto start_line;
            } elseif ($headerPos = strpos($buffer, "\n\n")) {
                $startLineAndHeaders = substr($buffer, 0, $headerPos + 1);
                $this->buffer = $buffer = (string) substr($buffer, $headerPos + 2);
                goto start_line;
            } elseif ($this->maxHeaderSize > 0 && strlen($this->buffer) > $this->maxHeaderSize) {
                $error = [431, "Bad Request: header size violation"];
                goto complete;
            } else {
                goto more_data_needed;
            }
        }

        start_line: {
            $startLineEndPos = strpos($startLineAndHeaders, "\n");
            $startLine = rtrim(substr($startLineAndHeaders, 0, $startLineEndPos), "\r\n");
            $rawHeaders = substr($startLineAndHeaders, $startLineEndPos + 1);
            $this->traceBuffer = $startLineAndHeaders;

            if (!$this->method = strtok($startLine, " ")) {
                $error = [400, "Bad Request: invalid request line"];
                goto complete;
            }

            if (!$this->uri = strtok(" ")) {
                $error = [400, "Bad Request: invalid request line"];
                goto complete;
            }

            $protocol = strtok(" ");
            if (stripos($protocol, "HTTP/") !== 0) {
                $error = [400, "Bad Request: invalid request line"];
                goto complete;
            }

            $protocol = substr($protocol, 5);
            if ($protocol === "1.1" || $protocol === "1.0") {
                $this->protocol = $protocol;
            } elseif ($protocol === "0.9") {
                $error = [505, "Protocol not supported"];
                goto complete;
            } else {
                $error = [400, "Bad Request: invalid protocol"];
                goto complete;
            }

            if ($rawHeaders) {
                goto parse_headers_from_raw;
            } else {
                goto transition_from_headers_to_body;
            }
        }

        parse_headers_from_raw: {
            if (strpos($rawHeaders, "\n\x20") || strpos($rawHeaders, "\n\t")) {
                $error = [400, "Bad Request: multi-line headers deprecated by RFC 7230"];
                goto complete;
            }

            if (!preg_match_all(self::ENTITY_HEADERS_PATTERN, $rawHeaders, $matches)) {
                $error = [400, "Bad Request: header syntax violation"];
                goto complete;
            }

            list($lines, $fields, $values) = $matches;

            $headers = [];
            foreach ($fields as $index => $field) {
                $headers[$field][] = $values[$index];
            }

            if ($headers) {
                $headers = array_change_key_case($headers, CASE_UPPER);
            }

            if (isset($headers["CONTENT-LENGTH"])) {
                $this->contentLength = (int) $headers["CONTENT-LENGTH"][0];
            }

            if (isset($headers["TRANSFER-ENCODING"])) {
                $value = $headers["TRANSFER-ENCODING"][0];
                $this->isChunked = (bool) strcasecmp($value, "identity");
            }

            // @TODO validate that the bytes in matched headers match the raw input. If not
            // there is a syntax error.

            $this->headers = $headers;
        }

        transition_from_headers_to_body: {
            if ($this->contentLength > $this->maxBodySize) {
                $error = [400, "Bad request: entity too large"];
            } elseif ($this->method == "HEAD" || $this->method == "TRACE" || $this->method == "OPTIONS") {
                // No body allowed for these messages
                goto complete;
            } elseif ($this->contentLength === 0) {
                // The strict comparison matters here because this is null for chunked bodies
                goto complete;
            } elseif ($this->isChunked) {
                $this->state = self::S_BODY_CHUNK_SIZE;
            } elseif ($this->contentLength) {
                $this->remainingBodyBytes = $this->contentLength;
                $this->state = self::S_BODY_IDENTITY;
            } else {
                goto complete;
            }

            $this->hasBody = true;

            $parseResult = [
                "trace"     => $this->traceBuffer,
                "protocol"  => $this->protocol,
                "method"    => $this->method,
                "uri"       => $this->uri,
                "headers"   => $this->headers,
                "body"      => "",
            ];

            $cb = $this->emitCallback;
            $emit = empty($error)
                ? [self::ENTITY_HEADERS, $parseResult, null]
                : [self::ERROR, $parseResult, $error];

            $cb($emit, $this->callbackData);

            goto start;
        }

        body_identity: {
            $bufferDataSize = strlen($this->buffer);

            if ($bufferDataSize < $this->remainingBodyBytes) {
                $this->remainingBodyBytes -= $bufferDataSize;
                $this->bodyBufferSize = $bufferDataSize;
                $this->body .= $this->buffer;
                $this->buffer = "";
                goto incomplete_body_data;
            } elseif ($bufferDataSize === $this->remainingBodyBytes) {
                $this->body .= $this->buffer;
                $this->buffer = "";
                $this->remainingBodyBytes = 0;
                goto complete;
            } else {
                $this->body .= substr($this->buffer, 0, $this->remainingBodyBytes);
                $this->buffer = (string) substr($this->buffer, $this->remainingBodyBytes);
                $this->remainingBodyBytes = 0;
                goto complete;
            }
        }

        body_chunk_size: {
            if (false === ($lineEndPos = strpos($this->buffer, "\r\n"))) {
                $this->state = self::S_BODY_CHUNK_SIZE;
                goto more_data_needed;
            } elseif ($lineEndPos === 0) {
                $error = [400, "Bad Request: hex chunk size expected"];
                goto complete;
            }

            $line = substr($this->buffer, 0, $lineEndPos);
            $hex = trim(ltrim($line, "0")) ?: 0;
            $dec = hexdec($hex);

            if ($hex == dechex($dec)) {
                $this->chunkLenRemaining = $dec;
            } else {
                $error = [400, "Bad Request: invalid chunk size"];
                goto complete;
            }

            $this->buffer = substr($this->buffer, $lineEndPos + 2);
            if ($dec === 0) {
                $this->state = self::S_TRAILERS_START;
                goto trailers_start;
            } elseif ($dec > $this->maxBodySize) {
                $error = [400, "Bad Request: excessive chunk size"];
                goto complete;
            } else {
                $this->state = self::S_BODY_CHUNKS;
            }
        }

        body_chunks: {
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
                $this->body .= $chunk;
                $this->chunkLenRemaining = null;
                $this->bodyBufferSize += $this->chunkLenRemaining;
                $resumeChunk = true;
                goto incomplete_body_data;
            } else {
                $this->body .= $this->buffer;
                $this->buffer = "";
                $this->bodyBufferSize += $bufferLen;
                $this->chunkLenRemaining -= $bufferLen;
                goto incomplete_body_data;
            }
        }

        trailers_start: {
            $firstTwoBytes = substr($this->buffer, 0, 2);

            if ($firstTwoBytes === false || $firstTwoBytes === "\r") {
                goto more_data_needed;
            } elseif ($firstTwoBytes === "\r\n") {
                $this->buffer = substr($this->buffer, 2);
                goto complete;
            } else {
                $this->state = self::S_TRAILERS;
                goto trailers;
            }
        }

        trailers: {
            if ($trailerSize = strpos($this->buffer, "\r\n\r\n")) {
                $trailers = substr($this->buffer, 0, $trailerSize + 2);
                $this->buffer = substr($this->buffer, $trailerSize + 4);
            } elseif ($trailerSize = strpos($this->buffer, "\n\n")) {
                $trailers = substr($this->buffer, 0, $trailerSize + 1);
                $this->buffer = substr($this->buffer, $trailerSize + 2);
            } else {
                $trailerSize = strlen($this->buffer);
                $trailers = null;
            }

            if ($this->maxHeaderSize > 0 && $trailerSize > $this->maxHeaderSize) {
                $error = [431, "Too much junk in the trunk (trailer size violation)"];
                goto complete;
            }

            // We perform this check AFTER validating trailer size so we can't be DoS'd by
            // maliciously-sized trailer headers.
            if (!isset($trailers)) {
                goto more_data_needed;
            }

            if (strpos($trailers, "\n\x20") || strpos($trailers, "\n\t")) {
                $error = [400, "Bad Request: multi-line trailers deprecated by RFC 7230"];
                goto complete;
            }

            if (!preg_match_all(self::ENTITY_HEADERS_PATTERN, $trailers, $matches)) {
                $error = [400, "Bad Request: trailer syntax violation"];
                goto complete;
            }

            list($lines, $fields, $values) = $matches;

            $trailers = [];
            foreach ($fields as $index => $field) {
                $trailers[$field][] = $values[$index];
            }

            if ($trailers) {
                $trailers = array_change_key_case($trailers, CASE_UPPER);
            }

            unset(
                $trailers['TRANSFER-ENCODING'],
                $trailers['CONTENT-LENGTH'],
                $trailers['TRAILER']
            );

            if ($trailers) {
                $this->headers = array_merge($this->headers, $trailers);
            }

            goto complete;
        }

        incomplete_body_data: {
            if ($this->bodyBufferSize >= $this->bodyEmitSize) {
                goto emit_body_part;
            } elseif (empty($resumeChunk)) {
                goto more_data_needed;
            } else {
                $resumeChunk = false;
                goto body_chunk_size;
            }
        }

        emit_body_part: {
            $bodyPart = $this->body;
            $this->body = '';
            $this->bodyBufferSize = 0;

            $cb = $this->emitCallback;
            $cb([self::ENTITY_PART, ["body" => $bodyPart], null], $this->callbackData);

            if (empty($isFinalBodyPart)) {
                goto start;
            } else {
                unset($isFinalBodyPart);
                goto complete;
            }
        }

        complete: {
            if ($this->body != '') {
                $isFinalBodyPart = true;
                goto emit_body_part;
            }

            $parseResult = [
                "trace"     => $this->traceBuffer,
                "protocol"  => $this->protocol,
                "method"    => $this->method,
                "uri"       => $this->uri,
                "headers"   => $this->headers,
                "body"      => "",
            ];

            if (empty($error)) {
                $resultCode = $this->hasBody ? self::ENTITY_RESULT : self::RESULT;
                $error = null;
            } else {
                $resultCode = self::ERROR;
            }

            $this->state = self::S_AWAITING_HEADERS;
            $this->traceBuffer = null;
            $this->headers = [];
            $this->body = null;
            $this->hasBody = null;
            $this->bodyBufferSize = 0;
            $this->bodyBytesConsumed = 0;
            $this->remainingBodyBytes = null;
            $this->chunkLenRemaining = null;
            $this->protocol = null;
            $this->uri = null;
            $this->method = null;

            $cb = $this->emitCallback;
            $cb([$resultCode, $parseResult, $error], $this->callbackData);

            if ($error) {
                return;
            } elseif (isset($this->buffer[0])) {
                goto start;
            } else {
                goto more_data_needed;
            }
        }

        more_data_needed: {
            return;
        }
    }
}
