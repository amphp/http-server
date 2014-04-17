<?php

namespace Aerys\Documents;

use Aerys\CustomResponseBody,
    Aerys\ResponseWriter,
    Aerys\PendingResponse;

class ThreadBody implements CustomResponseBody, ResponseWriter {
    private $dispatcher;
    private $path;
    private $size;
    private $task;

    public function __construct(Dispatcher $dispatcher, $path, $size) {
        $this->dispatcher = $dispatcher;
        $this->path = $path;
        $this->size = $size;
    }

    public function getContentLength() {
        return $this->size;
    }

    public function getResponseWriter(PendingResponse $pr) {
        $this->task = new ThreadSendTask($pr->headers, $this->path, $pr->destination);

        return $this;
    }

    public function writeResponse() {
        return $this->dispatcher->execute($this->task);
    }
}
