<?php

namespace Aerys;

use Alert\Reactor, Alert\Promise;

class StringWriter implements ResponseWriter {
    private $reactor;
    private $destination;
    private $writeWatcher;
    private $bufferString;
    private $bufferLength;
    private $promise;
    private $future;

    public function __construct(Reactor $reactor, PendingResponse $pr) {
        $this->reactor = $reactor;
        $this->destination = $pr->destination;
        $this->writeWatcher = $pr->writeWatcher;
        $this->bufferString = ($pr->headers . $pr->body);
        $this->bufferLength = strlen($this->bufferString);
    }

    public function writeResponse() {
        $bytesWritten = @fwrite($this->destination, $this->bufferString, $this->bufferLength);

        if ($bytesWritten === $this->bufferLength) {
            $this->bufferString = '';
            return $this->future ? $this->fulfillWritePromise() : self::COMPLETED;
        } elseif ($bytesWritten > 0) {
            $this->bufferString = substr($this->bufferString, $bytesWritten);
            $this->bufferLength -= $bytesWritten;
            return $this->future ?: $this->makeWritePromise();
        } elseif (is_resource($this->destination)) {
            return $this->future ?: $this->makeWritePromise();
        } else {
            return $this->future
                ? $this->failWritePromise(new TargetPipeException)
                : self::FAILURE;
        }
    }

    private function makeWritePromise() {
        $this->promise = new Promise;
        $this->reactor->enable($this->writeWatcher);
        $this->future = $this->promise->getFuture();

        return $this->future;
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
