<?php

namespace Aerys\Root;

use Amp\Future;
use Aerys\Responder;
use Aerys\ResponderStruct;
use Aerys\ClientGoneException;

class NaiveRootStreamResponder implements Responder {
    private $stream;
    private $header;
    private $buffer;
    private $bufferSize;
    private $streamSize;
    private $streamOffset;
    private $isWatcherEnabled;
    private $isStreamCopyComplete;
    private $promisor;
    private static $IO_GRANULARITY = 32768;

    public function __construct($stream, array $header, $streamSize) {
        $this->stream = $stream;
        $this->header = $header ? implode("\r\n", $header) : '';
        $this->streamSize = $streamSize;
    }

    /**
     * Prepare the Responder
     *
     * @param \Aerys\ResponderStruct $responderStruct
     * @return void
     */
    public function prepare(ResponderStruct $struct) {
        $this->struct = $struct;
        $this->promisor = new Future($this->struct->reactor);

        $request = $struct->request;
        $protocol = $request['SERVER_PROTOCOL'];
        $header = $this->header;

        if ($struct->mustClose || $protocol < 1.1) {
            $struct->mustClose = true;
            $header = setHeader($header, 'Connection', 'close');
        } else {
            // Append Connection header, don't set. There are scenarios where
            // multiple Connection headers are required (e.g. websockets).
            $header = addHeaderLine($header, "Connection: keep-alive");
            $header = setHeader($header, 'Keep-Alive', $struct->keepAlive);
        }

        $header = setHeader($header, 'Content-Length', $this->streamSize);
        $header = setHeader($header, 'Date', $struct->httpDate);

        if ($struct->serverToken) {
            $header = setHeader($header, 'Server', $struct->serverToken);
        }

        // IMPORTANT: This MUST happen AFTER other headers are normalized or headers
        // won't be correct when responding to HEAD requests. Don't move this above
        // the header normalization lines!
        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $this->isStreamWriteComplete = true;
        }

        $this->buffer = "HTTP/{$protocol} 200 OK\r\n{$header}\r\n\r\n";
        $this->bufferSize = strlen($this->buffer);
    }

    /**
     * Write the prepared Response
     *
     * @return \Amp\Promise
     */
    public function write() {
        if ($this->bufferSize) {
            $this->writeBuffer();
        } else {
            $this->copyStream();
        }

        return $this->promisor;
    }

    private function copyStream() {
        $maxLength = $this->streamSize - $this->streamOffset;
        if ($maxLength > self::$IO_GRANULARITY) {
            $maxLength = self::$IO_GRANULARITY;
        }

        $bytesWritten = stream_copy_to_stream(
            $this->stream,
            $this->struct->socket,
            $maxLength,
            $this->streamOffset
        );

        $this->streamOffset += $bytesWritten;

        if ($bytesWritten === false) {
            goto copy_error;
        } elseif ($this->streamSize - $this->streamOffset === 0) {
            goto copy_complete;
        } else {
            goto copy_incomplete;
        }

        copy_complete: {
            $this->isStreamCopyComplete = true;
            if ($this->isWatcherEnabled) {
                $this->struct->reactor->disable($this->struct->writeWatcher);
                $this->isWatcherEnabled = false;
            }

            return $this->promisor->succeed($this->struct->mustClose);
        }

        copy_incomplete: {
            if (!$this->isWatcherEnabled) {
                $this->isWatcherEnabled = true;
                $this->struct->reactor->enable($this->struct->writeWatcher);
            }

            return;
        }

        copy_error: {
            if ($this->isWatcherEnabled) {
                $this->struct->reactor->disable($this->struct->writeWatcher);
                $this->isWatcherEnabled = false;
            }

            return $this->promisor->fail(new ClientGoneException);
        }
    }

    private function writeBuffer() {
        $bytesWritten = @fwrite($this->struct->socket, $this->buffer);

        if ($bytesWritten === $this->bufferSize) {
            goto write_complete;
        } elseif ($bytesWritten !== false) {
            goto write_incomplete;
        } else {
            goto write_error;
        }

        write_complete: {
            $this->buffer = '';
            $this->bufferSize = 0;
            if (!$this->isStreamCopyComplete) {
                return $this->copyStream();
            }

            if ($this->isWatcherEnabled) {
                $this->struct->reactor->disable($this->struct->writeWatcher);
                $this->isWatcherEnabled = false;
            }

            return $this->promisor->succeed($this->struct->mustClose);
        }

        write_incomplete: {
            $this->bufferSize -= $bytesWritten;
            $this->buffer = substr($this->buffer, $bytesWritten);

            if (!$this->isWatcherEnabled) {
                $this->isWatcherEnabled = true;
                $this->struct->reactor->enable($this->struct->writeWatcher);
            }
            return;
        }

        write_error: {
            if ($this->isWatcherEnabled) {
                $this->isWatcherEnabled = false;
                $this->struct->reactor->disable($this->struct->writeWatcher);
            }

            return $this->promisor->fail(new ClientGoneException(
                'Write failed: destination stream went away'
            ));
        }
    }

}
