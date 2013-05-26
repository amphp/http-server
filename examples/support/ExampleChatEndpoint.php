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
        $this->clients->attach($client);
        $this->sendUserCount();
    }
    
    private function sendUserCount() {
        $toSend = '0' . $this->clients->count();
        foreach ($this->clients as $c) {
            $c->sendText($toSend);
        }
    }
    
    function onMessage(Client $client, Message $msg) {
        $addr = $this->clients->offsetGet($client);
        $payload = $msg->getPayload();
        $toSend = '1' . $payload;
        
        foreach ($this->clients as $c) {
            if ($client !== $c) {
                $c->sendText($toSend);
            }
        }
    }
    
    function onClose(Client $client, $code, $reason) {
        $this->clients->detach($client);
        $this->sendUserCount();
    }
    
}

