<?php

namespace Aerys\Documents;

use Aerys\Write\CustomBody,
    Aerys\Write\ResponseWriter,
    Aerys\Write\PendingResponse;

class ThreadBodyRange implements CustomBody, ResponseWriter {
    private $dispatcher;
    private $path;
    private $size;
    private $offset;
    private $task;

    public function __construct(Dispatcher $dispatcher, $path, $size, $offset) {
        $this->dispatcher = $dispatcher;
        $this->path = $path;
        $this->size = $size;
        $this->offset = $offset;
    }

    public function getContentLength() {
        return $this->size;
    }

    public function getResponseWriter(PendingResponse $pr) {
        $this->task = new ThreadSendRangeTask(
            $pr->headers,
            $this->path,
            $this->size,
            $this->offset,
            $pr->destination
        );

        return $this;
    }

    public function writeResponse() {
        return $this->dispatcher->execute($this->task);
    }
}
