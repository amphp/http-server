<?php

namespace Aerys\Root;

use Aerys\Responder;
use Aerys\ResponderEnvironment;

class BufferResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $environment;
    private $buffer;

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
        $body = ($method === 'HEAD') ? '' : $this->fileEntry->buffer;
        $this->buffer = "HTTP/{$protocol} 200 OK\r\n{$headers}\r\n\r\n{$body}";
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
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $env->mustClose);
        } elseif ($bytesWritten === false) {
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $mustClose = true);
        } else {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $env->reactor->enable($env->writeWatcher);
        }
    }
}
