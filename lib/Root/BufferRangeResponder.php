<?php

namespace Aerys\Root;

use Amp\Future;
use Amp\Success;
use Amp\Failure;
use Aerys\Responder;
use Aerys\ResponderStruct;

class BufferRangeResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $ranges;
    private $rangeBoundary;
    private $rangeContentType;
    private $rangeHeaderTemplate;
    private $reactor;
    private $socket;
    private $mustClose;
    private $writeWatcher;
    private $isWriteWatcherEnabled;
    private $promisor;

    public function __construct(FileEntry $fileEntry, array $headerLines, Range $range) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
        $this->ranges = $ranges = $range->ranges;
        $this->rangeBoundary = $range->boundary;
        $this->rangeContentType = $range->contentType;
        $this->rangeHeaderTemplate = $range->headerTemplate;
    }

    public function prepare(ResponderStruct $responderStruct) {
        $this->reactor = $responderStruct->reactor;
        $this->socket = $responderStruct->socket;
        $this->mustClose = $mustClose = $responderStruct->mustClose;
        $this->writeWatcher = $responderStruct->writeWatcher;

        $headerLines = $this->headerLines;

        if ($mustClose) {
            $headerLines[] = 'Connection: close';
        } else {
            $headerLines[] = 'Connection: keep-alive';
            $headerLines[] = "Keep-Alive: {$responderStruct->keepAlive}";
        }

        $headerLines[] = "Date: {$responderStruct->httpDate}";

        if ($responderStruct->serverToken) {
            $headerLines[] = "Server: {$responderStruct->serverToken}";
        }

        $request = $responderStruct->request;
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

    public function write() {
        $bytesWritten = @fwrite($this->socket, $this->buffer);

        if ($bytesWritten === strlen($this->buffer)) {
            goto write_complete;
        } elseif ($bytesWritten !== false) {
            goto write_incomplete;
        } else {
            goto write_error;
        }

        write_complete: {
            if ($this->isWriteWatcherEnabled) {
                $this->reactor->disable($this->writeWatcher);
                $this->isWriteWatcherEnabled = false;
            }

            return $this->promisor
                ? $this->promisor->succeed($this->mustClose)
                : new Success($this->mustClose);
        }

        write_incomplete: {
            $this->buffer = substr($this->buffer, $bytesWritten);

            if (!$this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = true;
                $this->reactor->enable($this->writeWatcher);
            }

            return $this->promisor ?: ($this->promisor = new Future($this->reactor));
        }

        write_error: {
            if ($this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = false;
                $this->reactor->disable($this->writeWatcher);
            }

            $error = new ClientGoneException(
                'Write failed: destination stream went away'
            );

            return $this->promisor ? $this->promisor->fail($error) : new Failure($error);
        }
    }
}
