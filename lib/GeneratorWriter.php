<?php

namespace Aerys;

use Alert\Reactor, Alert\Future, Alert\Promise, Alert\PromiseGroup;

class GeneratorWriter implements Writer {
    private $reactor;
    private $socket;
    private $writeWatcher;
    private $bufferString;
    private $bufferLength;
    private $promise;
    private $body;
    private $mustClose;
    private $awaitingFuture;
    private $outputCompleted = FALSE;
    private $targetPipeBroken = FALSE;

    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }

    public function prepareSubject($subject) {
        $this->socket = $subject->socket;
        $this->writeWatcher = $subject->writeWatcher;
        $this->bufferString = $subject->headers;
        $this->bufferLength = strlen($this->bufferString);
        $this->body = $subject->body;
        $this->mustClose = $subject->mustClose;
        $this->promise = new Promise;
        $this->futureResolver = function(Future $f) {
            $this->awaitingFuture = FALSE;
            $this->onFutureCompletion($f);
        };
    }

    public function writeResponse() {
        $bytesWritten = ($this->bufferLength > 0)
            ? @fwrite($this->socket, $this->bufferString, $this->bufferLength)
            : 0;

        if ($bytesWritten === $this->bufferLength) {
            $this->bufferString = '';
            $this->bufferNextElement();
        } elseif ($bytesWritten > 0) {
            $this->bufferString = substr($this->bufferString, $bytesWritten);
            $this->bufferLength -= $bytesWritten;
            $this->reactor->enable($this->writeWatcher);
        } elseif (!is_resource($this->socket)) {
            $this->targetPipeBroken = TRUE;
            $this->failWritePromise(new TargetPipeException);
        }

        return $this->promise;
    }

    private function bufferNextElement() {
        if ($this->outputCompleted) {
            $this->fulfillWritePromise();
        } elseif ($this->awaitingFuture) {
            // We can't proceed until the in-progress future value is resolved
            $this->reactor->disable($this->writeWatcher);
        } elseif ($this->body->valid()) {
            $this->advanceGenerator();
        } else {
            // It may look silly to buffer a final empty string but this is necessary to
            // accomodate both chunked and non-chunked entity bodies with the same code.
            // Chunked responses must send a final 0\r\n\r\n chunk to terminate the body.
            $this->outputCompleted = TRUE;
            $this->bufferBodyData("");
            $this->writeResponse();
        }
    }

    protected function bufferBodyData($data) {
        $this->bufferString .= $data;
        $this->bufferLength = strlen($this->bufferString);
    }

    private function advanceGenerator() {
        try {
            $value = $this->body->current();

            if ($value instanceof Future) {
                $this->awaitingFuture = TRUE;
                $value->onComplete($this->futureResolver);
            } elseif (is_array($value)) {
                $this->tryPromiseGroup($value);
            } elseif (is_scalar($value) && isset($value[0])) {
                $this->bufferBodyData($value);
                $this->body->next();
                $this->writeResponse();
            } else {
                $this->failWritePromise(new \DomainException(sprintf(
                    'Yielded values MUST be of type Future or non-empty scalar; %s returned', gettype($value)
                )));
            }
        } catch (\Exception $e) {
            $this->failWritePromise($e);
        }
    }

    private function tryPromiseGroup(array $futures) {
        try {
            (new PromiseGroup($futures))->getFuture()->onComplete($this->futureResolver);
        } catch (\InvalidArgumentException $e) {
            $this->failWritePromise(new \DomainException(sprintf(
                "Invalid yield array: non-empty array of Future instances required"
            )));
        }
    }

    private function onFutureCompletion(Future $future) {
        try {
            if ($this->targetPipeBroken) {
                // We're finished because the destination socket went away while we were
                // working to resolve this future.
                return;
            } elseif ($future->succeeded()) {
                $this->body->send($future->getValue());
                $this->bufferNextElement();
            } else {
                $this->body->throw($future->getError());
                $this->bufferNextElement();
            }
        } catch (\Exception $e) {
            $this->failWritePromise($e);
        }
    }

    private function fulfillWritePromise() {
        $this->reactor->disable($this->writeWatcher);
        $this->promise->succeed($this->mustClose);
    }

    private function failWritePromise(\Exception $e) {
        $this->reactor->disable($this->writeWatcher);
        $this->promise->fail($e);
    }
}
