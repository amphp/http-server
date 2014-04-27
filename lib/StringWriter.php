<?php

namespace Aerys;

use Alert\Reactor, After\Promise, After\Failure;

class StringWriter implements ResponseWriter {
    private $reactor;
    private $socket;
    private $watcher;
    private $response;
    private $mustClose;
    private $promise;

    public function __construct(Reactor $reactor, $socket, $watcher, $response, $mustClose) {
        $this->reactor = $reactor;
        $this->socket = $socket;
        $this->watcher = $watcher;
        $this->response = $response;
        $this->mustClose = $mustClose;
    }

    public function writeResponse() {
        $bytesWritten = @fwrite($this->socket, $this->response);

        if ($bytesWritten === strlen($this->response)) {
            return $this->promise ? $this->fulfillWritePromise() : $this->mustClose;
        } elseif ($bytesWritten > 0) {
            $this->response = substr($this->response, $bytesWritten);
            return $this->promise ?: $this->makeWritePromise();
        } elseif (is_resource($this->socket)) {
            return $this->promise ?: $this->makeWritePromise();
        } elseif ($this->promise) {
            $this->failWritePromise(new TargetPipeException);
        } else {
            return new Failure(new TargetPipeException);
        }
    }

    private function makeWritePromise() {
        $this->promise = new Promise;
        $this->reactor->enable($this->watcher);

        return $this->promise->getFuture();
    }

    private function fulfillWritePromise() {
        $this->reactor->disable($this->watcher);
        $this->promise->succeed($this->mustClose);
    }

    private function failWritePromise(\Exception $e) {
        $this->reactor->disable($this->watcher);
        $this->promise->fail($e);
    }
}
