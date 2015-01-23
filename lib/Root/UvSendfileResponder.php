<?php

namespace Aerys\Root;

use Aerys\Responder;
use Aerys\ResponderEnvironment;

class UvSendfileResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $environment;
    private $buffer;
    private $bodyBytesRemaining;
    private $isSendingFile;

    public function __construct(UvFileEntry $fileEntry, array $headerLines) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
        $this->bodyBytesRemaining = $fileEntry->size;
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

        if ($this->isSendingFile) {
            $env->reactor->disable($env->writeWatcher);
            $this->sendFile();
            return;
        }

        $bytesWritten = @fwrite($env->socket, $this->buffer);

        if ($bytesWritten === strlen($this->buffer)) {
            $this->buffer = null;
            $env->reactor->disable($env->writeWatcher);
            $this->isSendingFile = true;
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
        $socket = $env->socket;
        $handle = $this->fileEntry->handle;
        $offset = $this->fileEntry->size - $this->bodyBytesRemaining;
        $length = $this->bodyBytesRemaining;

        uv_fs_sendfile($uvLoop, $socket, $handle, $offset, $length, [$this, 'onComplete']);
    }

    private function onComplete($handle, $nwrite) {
        $env = $this->environment;

        if ($nwrite > 0) {
            $this->bodyBytesRemaining -= $nwrite;
        } elseif ($nwrite === -11) {
            // Error code -11 is "resource temporarily unavailable" ... this simply
            // means the write would block and we should try again later
            $env->reactor->enable($env->writeWatcher);
            return;
        } elseif ($nwrite < 0) {
            // Error -- we're finished.
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $mustClose = true);
            return;
        }

        if ($this->bodyBytesRemaining <= 0) {
            $env->server->resumeSocketControl($env->requestId, $env->mustClose);
        } else {
            $this->sendFile();
        }
    }
}
