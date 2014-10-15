<?php

namespace Aerys\Websocket;

use Amp\Reactor;
use Amp\Failure;
use Amp\Future;
use Aerys\Server;
use Aerys\TargetPipeException;
use Aerys\ResponseWriterCustom;
use Aerys\ResponseWriterSubject;

class HandshakeWriter implements ResponseWriterCustom {
    private $server;
    private $reactor;
    private $endpoint;
    private $request;
    private $response;
    private $socket;
    private $writeWatcher;
    private $promisor;

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
            return $this->promisor ? $this->succeedPromisor() : $this->exportSocket();
        } elseif ($bytesWritten > 0) {
            $this->response = substr($this->response, $bytesWritten);
            return $this->promisor ?: $this->makePromisor();
        } elseif (is_resource($this->socket)) {
            return $this->promisor ?: $this->makePromisor();
        } elseif ($this->promisor) {
            $this->failPromisor(new TargetPipeException);
        } else {
            return new Failure(new TargetPipeException);
        }
    }

    private function exportSocket() {
        list($socket, $closer) = $this->server->exportSocket($this->request['AERYS_SOCKET_ID']);
        $this->endpoint->import($socket, $closer, $this->request);
        return TRUE;
    }

    private function makePromisor() {
        $this->reactor->enable($this->writeWatcher);
        return $this->promisor = new Future($this->reactor);
    }

    private function succeedPromisor() {
        $this->reactor->disable($this->writeWatcher);
        $this->exportSocket();
        $this->promisor->succeed(TRUE);
    }

    private function failPromisor(\Exception $e) {
        $this->reactor->disable($this->writeWatcher);
        $this->promisor->fail($e);
    }
}
