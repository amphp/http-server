<?php

namespace Aerys\Root;

use Amp\Future;
use Aerys\Responder;
use Aerys\ResponderStruct;

abstract class StreamRangeResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $ranges;
    private $rangeBoundary;
    private $rangeContentType;
    private $rangeHeaderTemplate;

    private $reactor;
    private $promisor;
    private $socket;
    private $mustClose;
    private $writeWatcher;
    private $isWriteWatcherEnabled;

    private $streamPos;
    private $startPos;
    private $endPos;
    private $isFinalRange;
    private $isFinalWrite;

    private static $IO_GRANULARITY = 32768;

    /**
     * @param mixed $handle
     * @param int $offset
     * @param int $length
     * @param callable $onComplete
     * @return void
     */
    abstract protected function bufferFileChunk($handle, $offset, $length, callable $onComplete);

    public function __construct(FileEntry $fileEntry, array $headerLines, Range $range) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
        $this->ranges = $range->ranges;
        $this->rangeBoundary = $range->boundary;
        $this->rangeContentType = $range->contentType;
        $this->rangeHeaderTemplate = $range->headerTemplate;
    }

    public function prepare(ResponderStruct $responderStruct) {
        $this->reactor = $reactor = $responderStruct->reactor;
        $this->promisor = new Future($reactor);
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

        // We only use one trailing CRLF for multipart ranges because we'll
        // add another before the first MIME header.
        $trailingCrLf = empty($this->ranges[1]) ? "\r\n" : '';
        $this->buffer = "HTTP/{$protocol} 206 Partial Content\r\n{$headers}\r\n{$trailingCrLf}";

        if ($method === 'HEAD') {
            $this->isFinalWrite = true;
        }
    }

    public function write() {
        if (isset($this->buffer[0])) {
            goto write;
        } else {
            goto buffer_next_chunk;
        }

        write: {
            $bytesWritten = @fwrite($this->socket, $this->buffer);

            if ($bytesWritten === strlen($this->buffer)) {
                goto completed_buffer_write;
            } elseif ($bytesWritten !== false) {
                $this->buffer = substr($this->buffer, $bytesWritten);
                goto interleaved_write;
            } else {
                $error = new ClientGoneException(
                    'Write failed: destination stream went away'
                );
                goto finalize;
            }
        }

        completed_buffer_write: {
            $this->buffer = '';
            if ($this->isFinalWrite) {
                goto finalize;
            } else {
                goto interleaved_write;
            }
        }

        buffer_next_chunk: {
            if ($this->streamPos === $this->endPos) {
                goto buffer_next_header;
            }

            $length = $this->endPos - $this->streamPos;
            if ($length > self::$IO_GRANULARITY) {
                $length = self::$IO_GRANULARITY;
            }

            if ($this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = false;
                $this->reactor->disable($this->writeWatcher);
            }

            $handle = $this->fileEntry->handle;
            $offset = $this->streamPos;

            $this->bufferFileChunk($handle, $offset, $length, function($buffer) use ($length) {
                if ($buffer === false) {
                    $this->onBufferFailure($length);
                } else {
                    $this->buffer .= $buffer;
                    $this->streamPos += $length;
                    $this->write();
                }
            });

            return $this->promisor;
        }

        buffer_next_header: {
            if ($this->isFinalRange) {
                $this->buffer = "\r\n--{$this->rangeBoundary}--\r\n";
                $this->isFinalWrite = true;
                goto write;
            }


            $current = current($this->ranges);
            next($this->ranges);
            if (is_null(key($this->ranges))) {
                $this->isFinalRange = true;
            }

            list($this->startPos, $this->endPos) = $current;
            $this->streamPos = $this->startPos;
            $this->buffer = sprintf(
                $this->rangeHeaderTemplate,
                $this->rangeBoundary,
                $this->rangeContentType,
                $this->startPos,
                $this->endPos,
                $this->fileEntry->size
            );

            goto buffer_next_chunk;
        }

        interleaved_write: {
            if (!$this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = true;
                $this->reactor->enable($this->writeWatcher);
            }

            return $this->promisor;
        }

        finalize: {
            if ($this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = false;
                $this->reactor->disable($this->writeWatcher);
            }

            if (empty($error)) {
                $this->promisor->succeed($this->mustClose);
            } else {
                $this->promise->fail($error);
            }

            return $this->promisor;
        }
    }

    private function onBufferFailure($length) {
        $this->promisor->fail(new \RuntimeException(
            sprintf('Failed reading %s bytes from stream', $length)
        ));
        if ($this->isWriteWatcherEnabled) {
            $this->isWriteWatcherEnabled = false;
            $this->reactor->disable($this->writeWatcher);
        }
    }
}
