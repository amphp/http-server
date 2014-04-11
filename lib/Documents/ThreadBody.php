<?php

namespace Aerys\Documents;

use Aerys\Write\CustomBody,
    Aerys\Write\ResponseWriter,
    Aerys\Write\PendingResponse;

class ThreadBody implements CustomBody, ResponseWriter {
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
