<?php

namespace Aerys\Root;

use Amp\Future;
use Aerys\Responder;
use Aerys\ResponderStruct;
use Aerys\ClientGoneException;

class UvStreamResponder implements Responder {
    private $uvLoop;
    private $fileEntry;
    private $headerLines;
    private $reactor;
    private $writeWatcher;
    private $isWriteWatcherEnabled;
    private $socket;
    private $mustClose;
    private $promisor;
    private $buffer;
    private $fsBytesRead;

    private static $IO_GRANULARITY = 8192;

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
        $this->writeWatcher = $responderStruct->writeWatcher;
        $this->socket = $responderStruct->socket;
        $this->mustClose = $mustClose = $responderStruct->mustClose;

        $request = $responderStruct->request;
        $protocol = $request['SERVER_PROTOCOL'];
        $headerLines = $this->headerLines;

        if ($mustClose || $protocol < 1.1) {
            $this->mustClose = true;
            $headerLines[] = 'Connection: close';
        } else {
            $headerLines[] = 'Connection: keep-alive';
            $headerLines[] = "Keep-Alive: {$responderStruct->keepAlive}";
        }

        $headerLines[] = "Date: {$responderStruct->httpDate}";

        if ($responderStruct->serverToken) {
            $headerLines[] = "Server: {$responderStruct->serverToken}";
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
        if ($this->isWriteWatcherEnabled) {
            $this->isWriteWatcherEnabled = false;
            $this->reactor->disable($this->writeWatcher);
        }

        $this->promisor->fail(new \RuntimeException(
            sprintf('File system read failure: %s', $this->fileEntry->path)
        ));
    }

    /**
     * Write the prepared response
     *
     * @return \Amp\Promise Returns a promise that resolves to TRUE if the connection should be
     *                      closed and FALSE if not.
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
            $this->buffer = '';
            if ($this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = false;
                $this->reactor->disable($this->writeWatcher);
            }
            if ($this->fsBytesRead === $this->fileEntry->size) {
                $this->promisor->succeed($this->mustClose);
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
}
