<?php

namespace Aerys\DocRoot;

use Alert\Reactor,
    Alert\Promise,
    Alert\Failure,
    Aerys\ResponseWriterCustom,
    Aerys\ResponseWriterSubject,
    Aerys\TargetPipeException;

class StringResponseWriter implements ResponseWriterCustom {
    private $reactor;
    private $socket;
    private $writeWatcher;
    private $mustClose;
    private $promise;
    private $startLineAndHeaders;
    private $body;
    private $response;

    public function __construct(Reactor $reactor, $startLineAndHeaders, $body) {
        $this->reactor = $reactor;
        $this->startLineAndHeaders = $startLineAndHeaders;
        $this->body = $body;
    }

    public function prepareResponse(ResponseWriterSubject $subject) {
        $this->socket = $subject->socket;
        $this->writeWatcher = $subject->writeWatcher;
        $this->mustClose = $subject->mustClose;

        $extraHeaders = [];
        if ($subject->mustClose) {
            $extraHeaders[] = 'Connection: close';
        } else {
            $extraHeaders[] = 'Connection: keep-alive';
            $extraHeaders[] = $subject->keepAliveHeader;
        }
        if ($subject->serverHeader) {
            $extraHeaders[] = $subject->serverHeader;
        }
        $extraHeaders[] = $subject->dateHeader;
        $extraHeaders = implode("\r\n", $extraHeaders);

        $this->response = "{$this->startLineAndHeaders}\r\n{$extraHeaders}\r\n\r\n{$this->body}";
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
        $this->reactor->enable($this->writeWatcher);
        return $this->promise = new Promise;
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
