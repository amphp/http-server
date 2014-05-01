<?php

namespace Aerys\Blockable;

use After\Promise,
    After\Future,
    Amp\Dispatcher,
    Aerys\ResponseWriterCustom,
    Aerys\ResponseWriterSubject;

class ThreadResponseWriter implements ResponseWriterCustom {
    private $dispatcher;
    private $request;
    private $subject;
    private $promisor;

    public function __construct(Dispatcher $dispatcher, array $request) {
        $this->dispatcher = $dispatcher;
        $this->request = $request;
    }

    public function prepareResponse(ResponseWriterSubject $subject) {
        $this->subject = $subject;
    }

    public function writeResponse() {
        $subject = $this->subject;
        $socket = $subject->socket;
        $subject->socket = NULL;

        $request = $this->request;
        $input = $request['ASGI_INPUT'];
        unset($request['ASGI_ERROR'], $request['ASGI_INPUT'], $request['AERYS_STORAGE']);

        $task = new ThreadRequestTask($request, $socket, $input, $subject);
        $future = $this->dispatcher->execute($task);
        $future->onResolution([$this, 'afterWrite']);

        return $this->promisor = new Promise;
    }

    public function afterWrite(Future $future) {
        $shouldClose = $future->succeeded() ? (bool) $future->getValue() : TRUE;
        $this->promisor->succeed($shouldClose);
    }
}
