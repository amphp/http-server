<?php

namespace Aerys\Websocket;

use Alert\Reactor,
    Alert\Promise,
    Alert\Failure,
    Aerys\Server,
    Aerys\TargetPipeException,
    Aerys\ResponseWriterCustom,
    Aerys\ResponseWriterSubject;

class HandshakeWriter implements ResponseWriterCustom {
    private $server;
    private $reactor;
    private $endpoint;
    private $request;
    private $response;
    private $socket;
    private $writeWatcher;
    private $promise;

    public function __construct(Reactor $reactor, Server $server, Endpoint $endpoint, array $request, $response) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->endpoint = $endpoint;
        $this->request = $request;
        $this->response = $response;
    }

    public function prepareResponse(ResponseWriterSubject $subject) {
        $this->socket = $subject->socket;
        $this->writeWatcher = $subject->writeWatcher;
    }

    public function writeResponse() {
        $bytesWritten = @fwrite($this->socket, $this->response);

        if ($bytesWritten === strlen($this->response)) {
            return $this->promise ? $this->fulfillWritePromise() : $this->exportSocket();
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

    private function exportSocket() {
        list($socket, $closer) = $this->server->exportSocket($this->request['AERYS_SOCKET_ID']);
        $this->endpoint->import($socket, $closer, $this->request);
        return TRUE;
    }

    private function makeWritePromise() {
        $this->reactor->enable($this->writeWatcher);
        return $this->promise = new Promise;
    }

    private function fulfillWritePromise() {
        $this->reactor->disable($this->writeWatcher);
        $this->exportSocket();
        $this->promise->succeed(TRUE);
    }

    private function failWritePromise(\Exception $e) {
        $this->reactor->disable($this->writeWatcher);
        $this->promise->fail($e);
    }
}
