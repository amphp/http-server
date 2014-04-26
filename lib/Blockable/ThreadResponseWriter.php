<?php

namespace Aerys\Blockable;

use Alert\Promise,
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
        $this->promisor = new Promise;
        $subject = $this->subject;
        $socket = $subject->socket;
        $subject->socket = NULL;
        $task = new ThreadRequestTask($this->request, $socket, $subject);
        $this->dispatcher->execute($task)->onComplete([$this, 'afterWrite']);

        return $this->promisor;
    }

    public function afterWrite($dispatchFuture) {
        $shouldClose = $dispatchFuture->succeeded() ? (bool) $dispatchFuture->getValue() : TRUE;
        $this->promisor->succeed($shouldClose);
    }
}
