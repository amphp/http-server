<?php

use Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint;

class ExampleChatEndpoint implements Endpoint {
    
    private $clients;
    
    function __construct() {
        $this->clients = new \SplObjectStorage;
    }
    
    function onOpen(Client $client) {
        $asgiEnv = $client->getEnvironment();
        $addr = $asgiEnv['REMOTE_ADDR'];
        $this->clients->attach($client, $addr);
    }
    
    function onMessage(Client $client, Message $msg) {
        $addr = $this->clients->offsetGet($client);
        $payload = $msg->getPayload();
        $toSend = $addr . ': ' . $payload;
        
        foreach ($this->clients as $c) {
            if ($client !== $c) {
                $c->sendText($toSend);
            }
        }
    }
    
    function onClose(Client $client, $code, $reason) {
        $this->clients->detach($client);
    }
    
}

