<?php

namespace Aerys\Responders\Documents;

use Alert\Reactor;

class ThreadedFileLoader implements FileLoader {

    private $reactor;
    private $localSock;
    private $threadSock;
    private $sharedData;
    private $worker;
    private $ipcReadWatcher;
    private $tasks = [];

    function __construct(Reactor $reactor) {
        $this->reactor = $reactor;

        // @TODO USE UDP SOCKET FOR WINDOWS
        list($this->localSock, $this->threadSock) = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );
        stream_set_blocking($this->localSock, FALSE);

        $this->sharedData = new SharedData;
        $this->worker = new WorkerThread($this->sharedData, $this->threadSock);
        $this->worker->start();
        $this->ipcReadWatcher = $this->reactor->onReadable($this->localSock, [$this, 'onFulfillment']);
    }

    function onFulfillment() {
        fgetc($this->localSock);
        $fulfillment = $this->sharedData->shift();
        list($onComplete) = array_shift($this->tasks);
        $onComplete($fulfillment);
    }

    function getContents($path, callable $onComplete) {
        $task = new FileContentsTask($path);
        $this->tasks[] = [$onComplete, $task];
        $this->worker->stack($task);
    }

    function getHandle($path, callable $onComplete) {
        $task = new FileHandleTask($path);
        $this->tasks[] = [$onComplete, $task];
        $this->worker->stack($task);
    }

    function getMemoryMap($path, callable $onComplete) {
        $task = new FileMemoryMapTask($path);
        $this->tasks[] = [$onComplete, $task];
        $this->worker->stack($task);
    }

}
