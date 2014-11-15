<?php

namespace Aerys\Root;

use Amp\Future;
use Aerys\Responder;
use Aerys\ResponderStruct;
use Aerys\ClientGoneException;

class NaiveStreamResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $reactor;
    private $promisor;
    private $writeWatcher;
    private $socket;
    private $mustClose;
    private $buffer;
    private $streamOffset;
    private $isWriteWatcherEnabled;
    private $isStreamCopyComplete;

    private static $IO_GRANULARITY = 32768;

    public function __construct(FileEntry $fileEntry, array $headerLines) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
    }

    /**
     * Prepare the Responder
     *
     * @param \Aerys\ResponderStruct $responderStruct
     * @return void
     */
    public function prepare(ResponderStruct $responderStruct) {
        $this->reactor = $reactor = $responderStruct->reactor;
        $this->promisor = new Future($reactor);
        $this->writeWatcher = $responderStruct->writeWatcher;
        $this->socket = $responderStruct->socket;

        $request = $responderStruct->request;
        $protocol = $request['SERVER_PROTOCOL'];
        $headerLines = $this->headerLines;

        if ($responderStruct->mustClose || $protocol < 1.1) {
            $this->mustClose = true;
            $headerLines[] = 'Connection: close';
        } else {
            $this->mustClose = false;
            $headerLines[] = 'Connection: keep-alive';
            $headerLines[] = "Keep-Alive: {$responderStruct->keepAlive}";
        }

        $headerLines[] = "Date: {$responderStruct->httpDate}";

        if ($responderStruct->serverToken) {
            $headerLines[] = "Server: {$responderStruct->serverToken}";
        }

        $headers = implode("\r\n", $headerLines);
        $this->buffer = "HTTP/{$protocol} 200 OK\r\n{$headers}\r\n\r\n";

        if ($request['REQUEST_METHOD'] === 'HEAD' || $this->fileEntry->size == 0) {
            $this->isStreamWriteComplete = true;
        }
    }

    /**
     * Write the prepared Response
     *
     * @return \Amp\Promise
     */
    public function write() {
        if (isset($this->buffer[0])) {
            $this->writeBuffer();
        } else {
            $this->copyStream();
        }

        return $this->promisor;
    }

    private function writeBuffer() {
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
            if (!$this->isStreamCopyComplete) {
                return $this->copyStream();
            }

            if ($this->isWriteWatcherEnabled) {
                $this->reactor->disable($this->writeWatcher);
                $this->isWriteWatcherEnabled = false;
            }

            return $this->promisor->succeed($this->mustClose);
        }

        write_incomplete: {
            $this->buffer = substr($this->buffer, $bytesWritten);

            if (!$this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = true;
                $this->reactor->enable($this->writeWatcher);
            }
            return;
        }

        write_error: {
            if ($this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = false;
                $this->reactor->disable($this->writeWatcher);
            }

            return $this->promisor->fail(new ClientGoneException(
                'Write failed: destination stream went away'
            ));
        }
    }

    private function copyStream() {
        $maxLength = $this->fileEntry->size - $this->streamOffset;
        if ($maxLength > self::$IO_GRANULARITY) {
            $maxLength = self::$IO_GRANULARITY;
        }

        @fseek($this->fileEntry->handle, $this->streamOffset);

        $bytesWritten = stream_copy_to_stream(
            $this->fileEntry->handle,
            $this->socket,
            $maxLength,
            $this->streamOffset
        );

        $this->streamOffset += $bytesWritten;

        if ($bytesWritten === false) {
            goto copy_error;
        } elseif ($this->fileEntry->size - $this->streamOffset) {
            goto copy_incomplete;
        } else {
            goto copy_complete;
        }

        copy_complete: {
            $this->isStreamCopyComplete = true;
            if ($this->isWriteWatcherEnabled) {
                $this->reactor->disable($this->writeWatcher);
                $this->isWriteWatcherEnabled = false;
            }

            $this->promisor->succeed($this->mustClose);
            return;
        }

        copy_incomplete: {
            if (!$this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = true;
                $this->reactor->enable($this->writeWatcher);
            }

            return;
        }

        copy_error: {
            if ($this->isWriteWatcherEnabled) {
                $this->reactor->disable($this->writeWatcher);
                $this->isWriteWatcherEnabled = false;
            }

            $this->promisor->fail(new ClientGoneException);
            return;
        }
    }
}
