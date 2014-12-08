<?php

namespace Aerys\Root;

use Aerys\Responder;
use Aerys\ResponderEnvironment;

class BufferRangeResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $ranges;
    private $rangeBoundary;
    private $rangeContentType;
    private $rangeHeaderTemplate;
    private $environment;

    public function __construct(FileEntry $fileEntry, array $headerLines, Range $range) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
        $this->ranges = $ranges = $range->ranges;
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

        // We only use one trailing CRLF here because our multipart header template
        // will add another before the first MIME content element. Single range responses
        // also add their own CRLF.
        $buffer = "HTTP/{$protocol} 206 Partial Content\r\n{$headers}\r\n";

        if ($method !== 'HEAD') {
            $buffer.= isset($ranges[1]) ? $this->bufferMultipart() : $this->bufferRange();
        }

        $this->buffer = $buffer;
    }

    private function bufferMultipart() {
        $buffer = "";
        $template = $this->rangeHeaderTemplate;
        $boundary = $this->rangeBoundary;
        $totalSize = $this->fileEntry->size;
        $contentType = $this->rangeContentType;
        $fileContents = $this->fileEntry->buffer;

        foreach ($this->ranges as $range) {
            list($startPos, $endPos) = $range;
            $buffer .= sprintf($template, $boundary, $contentType, $startPos, $endPos, $totalSize);
            $buffer .= substr($fileContents, $startPos, $endPos);
        }

        $buffer .= "--{$boundary}--";

        return $buffer;
    }

    private function bufferRange() {
        list($startPos, $endPos) = $this->ranges[0];

        return "\r\n" . substr($this->fileEntry->buffer, $startPos, $endPos);
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
