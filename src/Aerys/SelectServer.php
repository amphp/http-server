<?php

namespace Aerys;

class SelectServer extends Server {
    
    private $writables = [];
    private $pendingSslSocks = [];
    
    final protected function run() {
        while (TRUE) {
            if ($this->canAcceptNewConnection()) {
                $this->accept();
            }
            if ($clientSocks = $this->getClientSocks()) {
                $this->select($clientSocks);
            }
            
            $this->timeoutIdleSockets();
            // if ($this->pendingSslSocks) { $this->processPendingSslConns(); }
        }
    }
    
    private function accept() {
        $r = $this->getInterfaces();
        $w = $e = NULL;
        if (!stream_select($r, $w, $e, 0, 150)) {
            return;
        }
        
        foreach ($r as $serverSock) {
            while ($clientSock = @stream_socket_accept($serverSock, 0, $peerName)) {
                stream_set_blocking($clientSock, FALSE);
                $this->onClient($clientSock, $peerName, $serverSock);
            }
        }
    }
    
    private function select(array $clientSocks) {
        $r = $this->writables = $clientSocks;
        $e = NULL;
        
        if (!stream_select($r, $w, $e, 0)) {
            return;
        }
        
        foreach ($r as $clientSock) {
            $this->onReadable($clientSock);
        }
        
        foreach ($this->writables as $clientSock) {
            $this->onWritable($clientSock);
        }
    }
    
    function close($clientId) {
        parent::close($clientId);
        unset($this->writables[$clientId]);
    }
    
}
