<?php

namespace Aerys;

use Aerys\Http\RequestParser,
    Aerys\Http\MessageWriter;

class Client {
    
    private $socket;
    private $ip;
    private $port;
    private $serverIp;
    private $serverPort;
    private $parser;
    private $writer;
    private $isCrypto;
    
    public $tempEntityWriter;
    public $midRequestInfo;
    public $requestCount = 0;
    public $pipeline = [];
    public $responses = [];
    
    function __construct($socket, $ip, $port, $serverIp, $serverPort, RequestParser $parser, MessageWriter $writer, $isCrypto) {
        $this->socket = $socket;
        $this->ip = $ip;
        $this->port = $port;
        $this->serverIp = $serverIp;
        $this->serverPort = $serverPort;
        $this->parser = $parser;
        $this->writer = $writer;
        $this->isCrypto = $isCrypto;
    }
    
    function getId() {
        return (int) $this->socket;
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
    
    function isCryptoEnabled() {
        return $this->isCrypto;
    }
    
    function getRequestCount() {
        return $this->requestCount;
    }
}
