<?php

namespace Aerys\Responders\Documents;

class WorkerThread extends \Worker {

    private $sharedData;
    private $ipcSocket;
    
    function __construct(SharedData $sharedData, $ipcSocket) {
        $this->sharedData = $sharedData;
        $this->ipcSocket = $ipcSocket;
    }
    
    function update($sharedData) {
        $this->sharedData[] = $sharedData;
        fwrite($this->ipcSocket, ".");
    }
    
    function run() {}
}
