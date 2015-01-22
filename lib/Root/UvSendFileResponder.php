<?php

namespace Aerys\Root;

use Aerys\Responder;
use Aerys\ResponderEnvironment;

class UvSendFileResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $environment;
    private $buffer;

    public function __construct(UvFileEntry $fileEntry, array $headerLines) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
    }

    /**
     * Prepare the Responder for client output
     *
     * @param \Aerys\ResponderEnvironment $env
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

        if ($serverToken = $env->serverToken) {
            $headerLines[] = "Server: {$serverToken}";
        }

        $request = $env->request;
        $protocol = $request['SERVER_PROTOCOL'];

        $headers = implode("\r\n", $headerLines);
        $this->buffer = "HTTP/{$protocol} 200 OK\r\n{$headers}\r\n\r\n";
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
            $this->buffer = null;
            $env->reactor->disable($env->writeWatcher);
            $this->sendFile();
        } elseif ($bytesWritten === false) {
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $mustClose = true);
        } else {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $env->reactor->enable($env->writeWatcher);
        }
    }

    private function sendFile() {
        $env = $this->environment;

        // If this was a HEAD request we don't need to send the body and we're finished
        if ($env->request['REQUEST_METHOD'] === 'HEAD') {
            $env->server->resumeSocketControl($env->requestId, $env->mustClose);
            return;
        }

        $uvLoop = $env->reactor->getUnderlyingLoop();
        $handle = $this->fileEntry->handle;
        $offset = 0;
        $length = $this->fileEntry->size;

        // We must set the stream to blocking, else uv_fs_sendfile may just send an incomplete response
        // This is the alternative to manually watching if the stream is readable and using uv_fs_sendfile() when possible
        // In this special case this is *not* a problem as libuv internally does the blocking sendfile() syscall in a separate thread
        stream_set_blocking($env->socket, true);
        uv_fs_sendfile($uvLoop, $env->socket, $handle, $offset, $length, [$this, 'onComplete']);
    }

    private function onComplete($handle, $nwrite) {
        $env = $this->environment;
        // We have explicitely set to blocking for uv_fs_sendfile, now reset to non blocking if we want to send any other data on this socket 
        stream_set_blocking($env->socket, false);
        $mustClose = ($nwrite < 0) ? true : $env->mustClose;
        $env->server->resumeSocketControl($env->requestId, $mustClose);
    }
}
