<?php

namespace Aerys\Root;

use Aerys\Responder;
use Aerys\ResponderEnvironment;

class UvStreamResponder implements Responder {
    private $uvLoop;
    private $fileEntry;
    private $headerLines;
    private $environment;
    private $buffer;
    private $fsBytesRead;

    private static $IO_GRANULARITY = 8192;

    public function __construct(UvFileEntry $fileEntry, array $headerLines) {
        $this->uvLoop = $fileEntry->uvLoop;
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
    }

    /**
     * Prepare the Responder for client output
     *
     * @param Aerys\ResponderStruct $env
     */
    public function prepare(ResponderEnvironment $env) {
        $this->environment = $env;

        $request = $env->request;
        $protocol = $request['SERVER_PROTOCOL'];
        $headerLines = $this->headerLines;

        if ($env->mustClose || $protocol < 1.1) {
            $env->mustClose = true;
            $headerLines[] = 'Connection: close';
        } else {
            $headerLines[] = 'Connection: keep-alive';
            $headerLines[] = "Keep-Alive: {$env->keepAlive}";
        }

        $headerLines[] = "Date: {$env->httpDate}";

        if ($env->serverToken) {
            $headerLines[] = "Server: {$env->serverToken}";
        }

        $headers = implode("\r\n", $headerLines);
        $this->buffer = "HTTP/{$protocol} 200 OK\r\n{$headers}\r\n\r\n";

        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $this->fsBytesRead = $this->fileEntry->size;
        } else {
            $this->bufferFromFileSystem();
        }
    }

    private function bufferFromFileSystem() {
        $fileEntry = $this->fileEntry;
        $handle = $fileEntry->handle;
        $length = $fileEntry->size - $this->fsBytesRead;
        if ($length > self::$IO_GRANULARITY) {
            $length = self::$IO_GRANULARITY;
        }

        uv_fs_read($this->uvLoop, $handle, $this->fsBytesRead, $length, [$this, 'onFsRead']);
    }

    private function onFsRead($handle, $nread, $buffer) {
        if ($nread < 0) {
            $this->onFsReadError();
            return;
        }

        $this->fsBytesRead += $nread;
        $this->buffer .= $buffer;

        if ($this->fileEntry->size - $this->fsBytesRead) {
            $this->bufferFromFileSystem();
        }

        $this->write();
    }

    private function onFsReadError() {
        $env = $this->environment;
        $server = $env->server;
        $env->reactor->disable($env->writeWatcher);
        $server->log("File system read failure: {$this->fileEntry->path}");
        $env->server->resumeSocketControl($env->requestId, $mustClose = true);
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
        $bytesWritten = @fwrite($env->socket, $this->buffer);

        if ($bytesWritten === strlen($this->buffer)) {
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
        $this->buffer = '';
        $env = $this->environment;
        $env->reactor->disable($env->writeWatcher);
        if ($this->fsBytesRead === $this->fileEntry->size) {
            $env->server->resumeSocketControl($env->requestId, $env->mustClose);
        }
    }
}
