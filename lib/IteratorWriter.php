<?php

namespace Aerys;

use Alert\Future, Alert\Promise;

class IteratorWriter implements ResponseWriter {

    private $reactor;
    private $destination;
    private $writeWatcher;
    private $bufferString;
    private $bufferLength;
    private $iteratorError;
    private $promise;
    private $future;
    private $body;
    private $outputCompleted = FALSE;
    private $destinationPipeBroken = FALSE;

    public function __construct(Reactor $reactor, PendingResponse $pr) {
        $this->reactor = $reactor;
        $this->destination = $pr->destination;
        $this->writeWatcher = $pr->writeWatcher;
        $this->bufferString = $pr->headers;
        $this->bufferLength = strlen($this->bufferString);
        $this->body = $pr->body;
        $this->promise = new Promise;
        $this->future = $this->promise->getFuture();
    }

    public function writeResponse() {
        $bytesWritten = ($this->bufferLength > 0)
            ? @fwrite($this->destination, $this->bufferString, $this->bufferLength)
            : 0;

        if ($bytesWritten === $this->bufferLength) {
            $this->bufferString = '';
            $this->bufferNextElement();
        } elseif ($bytesWritten > 0) {
            $this->bufferString = substr($this->bufferString, $bytesWritten);
            $this->bufferLength -= $bytesWritten;
            // Enable writability watching so we can finish sending this data
            $this->reactor->enable($this->writeWatcher);
        } elseif (!is_resource($this->destination)) {
            $this->destinationPipeBroken = TRUE;
            $this->failWritePromise(new TargetPipeException);
        }

        return $this->future;
    }

    private function bufferNextElement() {
        if ($this->outputCompleted) {
            $this->fulfillWritePromise();
        } elseif ($this->body->valid()) {
            $this->advanceIterator();
        } else {
            // It may look silly to buffer a final empty string but this is necessary to
            // accomodate both chunked and non-chunked entity bodies with the same code.
            // Chunked responses must send a final 0\r\n\r\n chunk to terminate the body.
            $this->outputCompleted = TRUE;
            $this->bufferBodyData("");
            $this->writeResponse();
        }
    }

    private function advanceIterator() {
        try {
            $value = $this->body->current();
            $this->body->next();

            if ($value instanceof Future) {
                // Disable writability watching until we resolve the future value
                $this->reactor->disable($this->writeWatcher);
                $value->onComplete(function(Future $f) {
                    $this->onFutureCompletion($f);
                });
            } elseif (is_scalar($value) && isset($value[0])) {
                $this->bufferBodyData($value);
                $this->writeResponse();
            } else {
                $this->failWritePromise(new \DomainException(sprintf(
                    'Entity futures must resolve a non-empty scalar; %s returned', gettype($value)
                )));
            }
        } catch (\Exception $e) {
            $this->failWritePromise($e);
        }
    }

    protected function bufferBodyData($data) {
        $this->bufferString .= $data;
        $this->bufferLength = strlen($this->bufferString);
    }

    private onFutureCompletion(Future $future) {
        try {
            if ($this->destinationPipeBroken) {
                // @TODO Consider logging the error resolutions even if the destination pipe is already gone
                // We're finished because the destination endpoint went away while we were
                // working to resolve this future.
                return;
            }

            $value = $future->getValue();

            if (is_scalar($value) && isset($value[0])) {
                $this->bufferBodyData($value);
                $this->writeResponse();
            } else {
                throw new \DomainException(sprintf(
                    'Entity futures must resolve a non-empty scalar; %s returned', gettype($value)
                ));
            }
        } catch (\Exception $e) {
            $this->failWritePromise($e));
        }
    }

    private function fulfillWritePromise() {
        $this->reactor->disable($this->writeWatcher);
        $this->promise->succeed();
    }

    private function failWritePromise(\Exception $e) {
        $this->reactor->disable($this->writeWatcher);
        $this->promise->fail($e);
    }
}
