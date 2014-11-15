<?php

namespace Aerys\Root;

use Amp\Future;
use Aerys\Responder;
use Aerys\ResponderStruct;
use Aerys\ClientGoneException;

class UvSendFileResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $reactor;
    private $promisor;
    private $socket;
    private $mustClose;
    private $writeWatcher;
    private $isHeadRequest;
    private $isWriteWatcherEnabled;
    private $buffer;

    public function __construct(UvFileEntry $fileEntry, array $headerLines) {
        $this->uvLoop = $fileEntry->uvLoop;
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
    }

    /**
     * Prepare the Responder
     *
     * @param Aerys\ResponderStruct $responderStruct
     */
    public function prepare(ResponderStruct $responderStruct) {
        $this->reactor = $reactor = $responderStruct->reactor;
        $this->promisor = new Future($reactor);
        $this->socket = $socket = $responderStruct->socket;
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

        if ($serverToken = $responderStruct->serverToken) {
            $headerLines[] = "Server: {$serverToken}";
        }

        $request = $responderStruct->request;
        $protocol = $request['SERVER_PROTOCOL'];
        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $this->isHeadRequest = true;
        }

        $headers = implode("\r\n", $headerLines);
        $this->buffer = "HTTP/{$protocol} 200 OK\r\n{$headers}\r\n\r\n";
    }

    /**
     * Write the prepared response
     *
     * @return \Amp\Promise Returns a promise that resolves to TRUE if the connection should be
     *                      closed, FALSE otherwise.
     */
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
            $this->buffer = null;

            if ($this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = false;
                $this->reactor->disable($this->writeWatcher);
            }

            if (!$this->isHeadRequest) {
                $this->sendFile();
            }

            return $this->promisor;
        }

        write_incomplete: {
            $this->buffer = substr($this->buffer, $bytesWritten);

            if (!$this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = true;
                $this->reactor->enable($this->writeWatcher);
            }

            return $this->promisor;
        }

        write_error: {
            if ($this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = false;
                $this->reactor->disable($this->writeWatcher);
            }

            $this->promisor->fail(new ClientGoneException(
                'Write failed: destination stream went away'
            ));

            return $this->promisor;
        }
    }

    private function sendFile() {
        $uvLoop = $this->reactor->getUnderlyingLoop();
        $socket = $this->socket;
        $handle = $this->fileEntry->handle;
        $offset = 0;
        $length = $this->fileEntry->size;

        uv_fs_sendfile($uvLoop, $socket, $handle, $offset, $length, [$this, 'onComplete']);
    }

    private function onComplete($handle, $nwrite) {
        if ($nwrite < 0) {
            $this->promisor->fail(new \RuntimeException(
                'Sendfile operation failed'
            ));
        } else {
            $this->promisor->succeed($this->mustClose);
        }
    }
}
