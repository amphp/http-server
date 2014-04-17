<?php

namespace Aerys\Documents;

use Aerys\Write\CustomResponseBody,
    Aerys\Write\ResponseWriter,
    Aerys\Write\PendingResponse;

class ThreadMultiRangeBody implements CustomResponseBody, ResponseWriter {
    private $dispatcher;
    private $path;
    private $size;
    private $ranges;
    private $boundary;
    private $type;
    private $task;

    public function __construct(Dispatcher $dispatcher, $path, $size, $ranges, $boundary, $type) {
        $this->dispatcher = $dispatcher;
        $this->path = $path;
        $this->size = $size;
        $this->ranges = $ranges;
        $this->boundary = $boundary;
        $this->type = $type;
    }

    public function getContentLength() {
        return $this->size;
    }

    public function getResponseWriter(PendingResponse $pr) {
        $this->task = new ThreadSendMultiRangeTask(
            $pr->headers,
            $this->path,
            $this->size,
            $this->ranges,
            $this->boundary,
            $this->type,
            $pr->destination
        );

        return $this;
    }

    public function writeResponse() {
        return $this->dispatcher->execute($this->task);
    }
}
