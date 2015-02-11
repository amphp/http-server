<?php

namespace Aerys;

class Parser {
    const ERROR = 1;
    const HEADERS = 2;
    const BODY_PART = 4;
    const RESULT = 8;

    const S_AWAITING_HEADERS = 0;
    const S_BODY_IDENTITY = 1;
    const S_BODY_IDENTITY_EOF = 2;
    const S_BODY_CHUNK_SIZE = 3;
    const S_BODY_CHUNKS = 4;
    const S_TRAILERS_START = 5;
    const S_TRAILERS = 6;

    const HEADERS_PATTERN = "/
        ([^\(\)<>@,;:\\\"\/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    /x";

    public static function parseRequest($data, ParseRequestContext $prc) {
        $buffer = $prc->buffer .= $data;

        if ($buffer == "") {
            goto more_data_needed;
        }

        start: {
            switch ($prc->state) {
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
                $prc->buffer = $buffer = (string) substr($buffer, $headerPos + 4);
                goto start_line;
            } elseif ($headerPos = strpos($buffer, "\n\n")) {
                $startLineAndHeaders = substr($buffer, 0, $headerPos + 1);
                $prc->buffer = $buffer = (string) substr($buffer, $headerPos + 2);
                goto start_line;
            } elseif ($prc->maxHeaderSize > 0 && strlen($prc->buffer) > $prc->maxHeaderSize) {
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
            $prc->traceBuffer = $startLineAndHeaders;

            if (!$prc->method = strtok($startLine, " ")) {
                $error = [400, "Bad Request: invalid request line"];
                goto complete;
            }

            if (!$prc->uri = strtok(" ")) {
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
                $prc->protocol = $protocol;
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

            if (!preg_match_all(self::HEADERS_PATTERN, $rawHeaders, $matches)) {
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
                $prc->contentLength = (int) $headers["CONTENT-LENGTH"][0];
            }

            if (isset($headers["TRANSFER-ENCODING"])) {
                $value = $headers["TRANSFER-ENCODING"][0];
                $prc->isChunked = (bool) strcasecmp($value, "identity");
            }

            // @TODO validate that the bytes in matched headers match the raw input. If not
            // there is a syntax error.

            $prc->headers = $headers;
        }

        transition_from_headers_to_body: {
            if ($prc->contentLength > $prc->maxBodySize) {
                $error = [400, "Bad request: entity too large"];
            } elseif ($prc->method == "HEAD" || $prc->method == "TRACE" || $prc->method == "OPTIONS") {
                // No body allowed for these messages
                goto complete;
            } elseif ($prc->contentLength === 0) {
                // The strict comparison matters here because this is null for chunked bodies
                goto complete;
            } elseif ($prc->isChunked) {
                $prc->state = self::S_BODY_CHUNK_SIZE;
            } elseif ($prc->contentLength) {
                $prc->remainingBodyBytes = $prc->contentLength;
                $prc->state = self::S_BODY_IDENTITY;
            } else {
                goto complete;
            }

            $partialResult = [
                "trace"    => $prc->traceBuffer,
                "protocol" => $prc->protocol,
                "method"   => $prc->method,
                "uri"      => $prc->uri,
                "headers"  => $prc->headers,
                "body"     => null,
            ];

            $cb = $prc->emitCallback;
            $emit = empty($error)
                ? [self::HEADERS, $partialResult, null]
                : [self::ERROR, $partialResult, $error];

            $cb($emit, $prc->appData);

            goto start;
        }

        body_identity: {
            $bufferDataSize = strlen($prc->buffer);

            if ($bufferDataSize < $prc->remainingBodyBytes) {
                $prc->remainingBodyBytes -= $bufferDataSize;
                $prc->bodyBufferSize = $bufferDataSize;
                $prc->body .= $prc->buffer;
                $prc->buffer = "";
                goto incomplete_body_data;
            } elseif ($bufferDataSize === $prc->remainingBodyBytes) {
                $prc->body .= $prc->buffer;
                $prc->buffer = "";
                $prc->remainingBodyBytes = 0;
                goto complete;
            } else {
                $prc->body = substr($prc->buffer, 0, $prc->remainingBodyBytes);
                $prc->buffer = substr($prc->buffer, $prc->remainingBodyBytes);
                $prc->remainingBodyBytes = 0;
                goto complete;
            }
        }

        body_chunk_size: {
            if (false === ($lineEndPos = strpos($prc->buffer, "\r\n"))) {
                $prc->state = self::S_BODY_CHUNK_SIZE;
                goto more_data_needed;
            } elseif ($lineEndPos === 0) {
                $error = [400, "Bad Request: hex chunk size expected"];
                goto complete;
            }

            $line = substr($prc->buffer, 0, $lineEndPos);
            $hex = trim(ltrim($line, "0")) ?: 0;
            $dec = hexdec($hex);

            if ($hex == dechex($dec)) {
                $prc->chunkLenRemaining = $dec;
            } else {
                $error = [400, "Bad Request: invalid chunk size"];
                goto complete;
            }

            $prc->buffer = substr($prc->buffer, $lineEndPos + 2);
            if ($dec === 0) {
                $prc->state = self::S_TRAILERS_START;
                goto trailers_start;
            } elseif ($dec > $prc->maxBodySize) {
                $error = [400, "Bad Request: excessive chunk size"];
                goto complete;
            } else {
                $prc->state = self::S_BODY_CHUNKS;
            }
        }

        body_chunks: {
            $bufferLen = strlen($prc->buffer);

            // These first two (extreme) edge cases prevent errors where the packet boundary ends after
            // the \r and before the \n at the end of a chunk.
            if ($bufferLen === $prc->chunkLenRemaining) {
                goto more_data_needed;
            } elseif ($bufferLen === $prc->chunkLenRemaining + 1) {
                goto more_data_needed;
            } elseif ($bufferLen >= $prc->chunkLenRemaining + 2) {
                $chunk = substr($prc->buffer, 0, $prc->chunkLenRemaining);
                $prc->buffer = substr($prc->buffer, $prc->chunkLenRemaining + 2);
                $prc->body .= $chunk;
                $prc->chunkLenRemaining = null;
                $prc->bodyBufferSize += $prc->chunkLenRemaining;
                $resumeChunk = true;
                goto incomplete_body_data;
            } else {
                $prc->body .= $prc->buffer;
                $prc->buffer = "";
                $prc->bodyBufferSize += $bufferLen;
                $prc->chunkLenRemaining -= $bufferLen;
                goto incomplete_body_data;
            }
        }

        trailers_start: {
            $firstTwoBytes = substr($prc->buffer, 0, 2);

            if ($firstTwoBytes === false || $firstTwoBytes === "\r") {
                goto more_data_needed;
            } elseif ($firstTwoBytes === "\r\n") {
                $prc->buffer = substr($prc->buffer, 2);
                goto complete;
            } else {
                $prc->state = self::S_TRAILERS;
                goto trailers;
            }
        }

        trailers: {
            if ($trailerSize = strpos($prc->buffer, "\r\n\r\n")) {
                $trailers = substr($prc->buffer, 0, $trailerSize + 2);
                $prc->buffer = substr($prc->buffer, $trailerSize + 4);
            } elseif ($trailerSize = strpos($prc->buffer, "\n\n")) {
                $trailers = substr($prc->buffer, 0, $trailerSize + 1);
                $prc->buffer = substr($prc->buffer, $trailerSize + 2);
            } else {
                $trailerSize = strlen($prc->buffer);
                $trailers = null;
            }

            if ($prc->maxHeaderSize > 0 && $trailerSize > $prc->maxHeaderSize) {
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

            if (!preg_match_all(self::HEADERS_PATTERN, $trailers, $matches)) {
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
                $prc->headers = array_merge($prc->headers, $trailers);
            }

            goto complete;
        }

        incomplete_body_data: {
            if ($prc->bodyBufferSize >= $prc->bodyEmitSize) {
                goto emit_body_part;
            } elseif (empty($resumeChunk)) {
                goto more_data_needed;
            } else {
                $resumeChunk = false;
                goto body_chunk_size;
            }
        }

        emit_body_part: {
            $bodyPart = $prc->body;
            $prc->body = '';
            $prc->bodyBufferSize = 0;

            $parseResult = [
                "trace"       => $prc->traceBuffer,
                "protocol"    => $prc->protocol,
                "method"      => $prc->method,
                "uri"         => $prc->uri,
                "headers"     => $prc->headers,
                "body"        => $bodyPart,
            ];

            $cb = $prc->emitCallback;
            $cb([self::BODY_PART, $parseResult, null], $prc->appData);

            goto start;
        }

        complete: {
            $parseResult = [
                "trace"       => $prc->traceBuffer,
                "protocol"    => $prc->protocol,
                "method"      => $prc->method,
                "uri"         => $prc->uri,
                "headers"     => $prc->headers,
                "body"        => $prc->body,
            ];

            if (empty($error)) {
                $resultCode = self::RESULT;
                $error = null;
            } else {
                $resultCode = self::ERROR;
            }

            $prc->state = self::S_AWAITING_HEADERS;
            $prc->traceBuffer = null;
            $prc->headers = [];
            $prc->body = null;
            $prc->bodyBufferSize = 0;
            $prc->bodyBytesConsumed = 0;
            $prc->remainingBodyBytes = null;
            $prc->chunkLenRemaining = null;
            $prc->protocol = null;
            $prc->uri = null;
            $prc->method = null;

            $cb = $prc->emitCallback;
            $cb([$resultCode, $parseResult, $error], $prc->appData);

            if ($error) {
                return;
            } elseif (isset($prc->buffer[0])) {
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
