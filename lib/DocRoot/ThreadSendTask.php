<?php

namespace Aerys\DocRoot;

use Amp\Thread;

/**
 * The socket remains in non-blocking mode even though we're operating inside a worker thread.
 * We can't tell it to block because this could affect read operations in the main thread where
 * blocking behavior is unacceptable. As a result, we loop when writing to ensure all data is
 * sent before proceeding.
 */
class ThreadSendTask extends \Threaded {
    private $socket;
    private $startLineAndHeaders;
    private $filePath;
    private $fileSize;

    public function __construct($socket, $startLineAndHeaders, $filePath, $fileSize) {
        $this->socket = $socket;
        $this->startLineAndHeaders = $startLineAndHeaders;
        $this->filePath = $filePath;
        $this->fileSize = $fileSize;
    }

    public function run() {
        $fileHandle = @fopen($this->filePath, 'r');

        if ($fileHandle === FALSE) {
            $this->worker->registerResult(Thread::FAILURE, new \RuntimeException(
                sprintf('Failed opening file handle: %s', $this->filePath)
            ));
            return;
        }

        if (!$this->writeHeaders()) {
            $this->worker->registerResult(Thread::FAILURE, new \RuntimeException(
                'Socket went away while writing headers'
            ));
            return;
        }

        if ($this->writeBody($fileHandle)) {
            $this->worker->registerResult(Thread::SUCCESS, $result = NULL);
        } else {
            $this->worker->registerResult(Thread::FAILURE, new \RuntimeException(
                'Socket went away while writing entity body'
            ));
        }
    }

    public function writeBody($fileHandle) {
        $maxLength = -1;
        $bytesWritten = 0;
        while ($bytesWritten < $this->fileSize) {
            $bytes = @stream_copy_to_stream($fileHandle, $this->socket, $maxLength, $bytesWritten);
            $bytesWritten += $bytes;
            if ($bytes === FALSE) {
                return FALSE;
            }
        }

        return TRUE;
    }

    public function writeHeaders() {
        while (TRUE) {
            $bytesWritten = @fwrite($this->socket, $this->startLineAndHeaders);

            if ($bytesWritten === strlen($this->startLineAndHeaders)) {
                return TRUE;
            } elseif ($bytesWritten > 0) {
                $this->startLineAndHeaders = substr($this->startLineAndHeaders, $bytesWritten);
            } elseif (!is_resource($this->socket)) {
                return FALSE;
            }
        }
    }
}
