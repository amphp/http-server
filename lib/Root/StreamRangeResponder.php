<?php

namespace Aerys\Root;

use Aerys\Responder;
use Aerys\ResponderEnvironment;

abstract class StreamRangeResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $ranges;
    private $rangeBoundary;
    private $rangeContentType;
    private $rangeHeaderTemplate;
    private $env;
    private $streamPos;
    private $startPos;
    private $endPos;
    private $isFinalRange;
    private $isFinalWrite;

    private static $IO_GRANULARITY = 32768;

    /**
     * @param mixed $handle
     * @param int $offset
     * @param int $length
     * @param callable $onComplete
     * @return void
     */
    abstract protected function bufferFileChunk($handle, $offset, $length, callable $onComplete);

    public function __construct(FileEntry $fileEntry, array $headerLines, Range $range) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
        $this->ranges = $range->ranges;
        $this->rangeBoundary = $range->boundary;
        $this->rangeContentType = $range->contentType;
        $this->rangeHeaderTemplate = $range->headerTemplate;
    }

    /**
     * Prepare the Responder for client output
     *
     * @param \Aerys\ResponderEnvironment $env
     * @return void
     */
    public function prepare(ResponderEnvironment $env) {
        $this->environment = $env;

        $headerLines = $this->headerLines;

        if ($env->mustClose) {
            $headerLines[] = 'Connection: close';
        } else {
            $headerLines[] = 'Connection: keep-alive';
            $headerLines[] = "Keep-Alive: {$env->keepAlive}";
        }

        $headerLines[] = "Date: {$env->httpDate}";

        if ($env->serverToken) {
            $headerLines[] = "Server: {$env->serverToken}";
        }

        $request = $env->request;
        $method = $request['REQUEST_METHOD'];
        $protocol = $request['SERVER_PROTOCOL'];
        $headers = implode("\r\n", $headerLines);

        // We only use one trailing CRLF for multipart ranges because we'll
        // add another before the first MIME header.
        $trailingCrLf = empty($this->ranges[1]) ? "\r\n" : '';
        $this->buffer = "HTTP/{$protocol} 206 Partial Content\r\n{$headers}\r\n{$trailingCrLf}";

        if ($method === 'HEAD') {
            $this->isFinalWrite = true;
        }

        // If the request was for a single zero-length byterange we don't need any further writes
        if (empty($this->ranges[1]) && $this->ranges[0][0] === $this->ranges[0][1]) {
            $this->isFinalWrite = true;
        }
    }

    /**
     * Assume control of the client socket and output the prepared response
     *
     * @return void
     */
    public function assumeSocketControl() {
        $this->write();
    }

    /**
     * Write the prepared response
     *
     * @return void
     */
    public function write() {
        $env = $this->environment;
        if (isset($this->buffer[0])) {
            goto write;
        } else {
            goto buffer_next_chunk;
        }

        write: {
            $bytesWritten = @fwrite($env->socket, $this->buffer);

            if ($bytesWritten === strlen($this->buffer)) {
                goto completed_buffer_write;
            } elseif ($bytesWritten !== false) {
                $this->buffer = substr($this->buffer, $bytesWritten);
                goto interleaved_write;
            } else {
                $mustClose = true;
                goto finalize;
            }
        }

        completed_buffer_write: {
            $this->buffer = '';
            if ($this->isFinalWrite) {
                $mustClose = $env->mustClose;
                goto finalize;
            } else {
                goto interleaved_write;
            }
        }

        buffer_next_chunk: {
            if ($this->streamPos === $this->endPos) {
                goto buffer_next_header;
            }

            $length = $this->endPos - $this->streamPos;
            if ($length > self::$IO_GRANULARITY) {
                $length = self::$IO_GRANULARITY;
            }

            $env->reactor->disable($env->writeWatcher);

            $handle = $this->fileEntry->handle;
            $offset = $this->streamPos;

            $this->bufferFileChunk($handle, $offset, $length, [$this, 'onBuffer']);
            return;
        }

        buffer_next_header: {
            if ($this->isFinalRange) {
                $this->buffer = "\r\n--{$this->rangeBoundary}--\r\n";
                $this->isFinalWrite = true;
                goto write;
            }

            $current = current($this->ranges);
            next($this->ranges);
            if (is_null(key($this->ranges))) {
                $this->isFinalRange = true;
            }

            list($this->startPos, $this->endPos) = $current;
            $this->streamPos = $this->startPos;
            $this->buffer = sprintf(
                $this->rangeHeaderTemplate,
                $this->rangeBoundary,
                $this->rangeContentType,
                $this->startPos,
                $this->endPos,
                $this->fileEntry->size
            );

            goto buffer_next_chunk;
        }

        interleaved_write: {
            $env->reactor->enable($env->writeWatcher);
            return;
        }

        finalize: {
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $mustClose);
            return;
        }
    }

    public function onBuffer($buffer) {
        if ($buffer === false) {
            $env = $this->environment;
            $env->reactor->disable($env->writeWatcher);
            $env->server->log("Filesystem read failed: {$this->fileEntry->path}");
            $env->server->resumeSocketControl($env->requestId, $mustClose = true);
        } else {
            $this->buffer .= $buffer;
            $this->streamPos += strlen($buffer);
            $this->write();
        }
    }
}
