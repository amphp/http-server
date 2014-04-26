<?php

namespace Aerys\Documents;

use Amp\Dispatcher,
    Alert\Promise,
    Alert\Future,
    Aerys\Writer;

class ThreadResponseWriter implements Writer {
    private $dispatcher;
    private $startLineAndHeaders;
    private $filePath;
    private $fileSize;
    private $mustClose;
    private $sendTask;
    private $promise;

    public function __construct(Dispatcher $dispatcher, $startLineAndHeaders, $filePath, $fileSize) {
        $this->dispatcher = $dispatcher;
        $this->startLineAndHeaders = $startLineAndHeaders;
        $this->filePath = $filePath;
        $this->fileSize = $fileSize;
    }

    public function prepareSubject($subject) {
        $this->mustClose = $subject->mustClose;

        $extraHeaders = [];
        $extraHeaders[] = $subject->dateHeader;
        if ($subject->mustClose) {
            $extraHeaders[] = 'Connection: close';
        } else {
            $extraHeaders[] = 'Connection: keep-alive';
            $extraHeaders[] = $subject->keepAliveHeader;
        }
        if ($subject->serverHeader) {
            $extraHeaders[] = $subject->serverHeader;
        }
        $extraHeaders = implode("\r\n", $extraHeaders);
        $startLineAndHeaders = "{$this->startLineAndHeaders}\r\n{$extraHeaders}\r\n\r\n";

        $this->sendTask = new ThreadSendTask(
            $subject->socket,
            $startLineAndHeaders,
            $this->filePath,
            $this->fileSize
        );
    }

    public function writeResponse() {
        $future = $this->dispatcher->execute($this->sendTask);
        $future->onComplete(function($future) {
            $this->afterWrite($future);
        });

        return $this->promise = new Promise;
    }

    private function afterWrite(Future $future) {
        if ($future->succeeded()) {
            $this->promise->succeed($this->mustClose);
        } else {
            $this->promise->fail($future->getError());
        }
    }
}
