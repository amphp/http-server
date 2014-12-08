<?php

namespace Aerys\Root;

use Aerys\Responder;
use Aerys\ResponderEnvironment;

class NaiveStreamResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $env;
    private $buffer;
    private $streamOffset;
    private $isStreamCopyComplete;

    private static $IO_GRANULARITY = 32768;

    public function __construct(FileEntry $fileEntry, array $headerLines) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
    }

    /**
     * Prepare the Responder for client output
     *
     * @param \Aerys\ResponderEnvironment $env
     * @return void
     */
    public function prepare(ResponderEnvironment $env) {
        $this->environment = $env;

        $request = $env->request;
        $protocol = $request['SERVER_PROTOCOL'];
        $headerLines = $this->headerLines;

        if ($env->mustClose || $protocol < 1.1) {
            $this->mustClose = true;
            $headerLines[] = 'Connection: close';
        } else {
            $this->mustClose = false;
            $headerLines[] = 'Connection: keep-alive';
            $headerLines[] = "Keep-Alive: {$env->keepAlive}";
        }

        $headerLines[] = "Date: {$env->httpDate}";

        if ($env->serverToken) {
            $headerLines[] = "Server: {$env->serverToken}";
        }

        $headers = implode("\r\n", $headerLines);
        $this->buffer = "HTTP/{$protocol} 200 OK\r\n{$headers}\r\n\r\n";

        if ($request['REQUEST_METHOD'] === 'HEAD' || $this->fileEntry->size == 0) {
            $this->isStreamWriteComplete = true;
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
     * Write the prepared Response
     *
     * @return void
     */
    public function write() {
        if (isset($this->buffer[0])) {
            $this->writeBuffer();
        } else {
            $this->copyStream();
        }
    }

    private function writeBuffer() {
        $env = $this->environment;
        $bytesWritten = @fwrite($env->socket, $this->buffer);

        if ($bytesWritten === strlen($this->buffer)) {
            $this->buffer = '';
            $this->onBufferWriteCompletion();
        } elseif ($bytesWritten === false) {
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $mustClose = true);
        } else {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $env->reactor->enable($env->writeWatcher);
        }
    }

    private function onBufferWriteCompletion() {
        if ($this->isStreamCopyComplete) {
            $env = $this->environment;
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $env->mustClose);
        } else {
            $this->copyStream();
        }
    }

    private function copyStream() {
        $env = $this->environment;
        $maxLength = $this->fileEntry->size - $this->streamOffset;
        if ($maxLength > self::$IO_GRANULARITY) {
            $maxLength = self::$IO_GRANULARITY;
        }

        @fseek($this->fileEntry->handle, $this->streamOffset);

        $bytesWritten = @stream_copy_to_stream(
            $this->fileEntry->handle,
            $env->socket,
            $maxLength,
            $this->streamOffset
        );

        $this->streamOffset += $bytesWritten;

        if ($bytesWritten === false) {
            $errorMsg = error_get_last()['message'];
            $this->onStreamCopyFailure($errorMsg);
        } elseif ($this->fileEntry->size - $this->streamOffset) {
            $env->reactor->enable($env->writeWatcher);
        } else {
            $this->isStreamCopyComplete = true;
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $env->mustClose);
        }
    }

    private function onStreamCopyFailure($errorMsg) {
        $env = $this->environment;
        if (strpos($errorMsg, 'errno=11')) {
            // EAGAIN or EWOULDBLOCK -- all we can do is try again when the socket is writable
            $env->reactor->enable($env->writeWatcher);
        } else {
            // The write failed for some other reason (it's probably dead)
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $mustClose = true);
        }
    }
}
