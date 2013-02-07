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
    
    public $tempEntityWriter;
    public $midRequestInfo;
    public $requestCount = 0;
    public $pipeline = [];
    public $responses = [];
    
    function __construct($socket, $peerName, $serverName, RequestParser $parser, MessageWriter $writer) {
        $portStart = strrpos($peerName, ':');
        $clientIp = substr($peerName, 0, $portStart);
        $clientPort = substr($peerName, $portStart + 1);
        
        $portStart = strrpos($serverName, ':');
        $serverIp = substr($serverName, 0, $portStart);
        $serverPort = substr($serverName, $portStart + 1);
        
        $this->id = (int) $socket;
        $this->socket = $socket;
        $this->ip = $clientIp;
        $this->port = $clientPort;
        $this->serverIp = $serverIp;
        $this->serverPort = $serverPort;
        $this->parser = $parser;
        $this->writer = $writer;
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
