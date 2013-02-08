<?php

namespace Aerys;

use Aerys\Http\RequestParser,
    Aerys\Http\MessageWriter;

class Client {
    
    private $id;
    private $socket;
    private $ip;
    private $port;
    private $serverIp;
    private $serverPort;
    private $parser;
    private $writer;
    
    public $requestCount = 0;
    public $pipeline = [];
    public $responses = [];
    
    private $preBodyRequestInfo;
    
    function __construct($socket, $peerName, $serverName, RequestParser $parser, MessageWriter $writer) {
        $clientPortStartPos = strrpos($peerName, ':');
        $clientIp = substr($peerName, 0, $clientPortStartPos);
        $clientPort = substr($peerName, $clientPortStartPos + 1);
        
        $serverPortStartPos = strrpos($serverName, ':');
        $serverIp = substr($serverName, 0, $serverPortStartPos);
        $serverPort = substr($serverName, $serverPortStartPos + 1);
        
        $this->id = (int) $socket;
        $this->socket = $socket;
        $this->ip = $clientIp;
        $this->port = $clientPort;
        $this->serverIp = $serverIp;
        $this->serverPort = $serverPort;
        $this->parser = $parser;
        $this->writer = $writer;
    }
    
    function storePreBodyRequestInfo(array $info) {
        $this->preBodyRequestInfo = $info;
    }
    
    function shiftPreBodyRequestInfo() {
        if ($info = $this->preBodyRequestInfo) {
            $this->preBodyRequestInfo = NULL;
            return $info;
        }
    }
    
    function getId() {
        return $this->id;
    }
    
    function getSocket() {
        return $this->socket;
    }
    
    function getIp() {
        return $this->ip;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getServerIp() {
        return $this->serverIp;
    }
    
    function getServerPort() {
        return $this->serverPort;
    }
    
    function getParser() {
        return $this->parser;
    }
    
    function getWriter() {
        return $this->writer;
    }
    
    function getRequestCount() {
        return $this->requestCount;
    }
}
