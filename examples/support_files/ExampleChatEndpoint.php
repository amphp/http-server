<?php

use Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\EndpointOptions;

class ExampleChatEndpoint implements Endpoint {
    
    private $clients;
    private $options;
    
    function __construct() {
        $this->clients = new \SplObjectStorage;
    }
    
    function onOpen(Client $client) {
        $this->clients->attach($client);
    }
    
    function onMessage(Client $client, Message $msg) {
        $payload = $msg->getPayload();
        foreach ($this->clients as $c) {
            if ($client !== $c) {
                $c->sendText($payload);
            }
        }
    }
    
    function onClose(Client $client, $code, $reason) {
        $this->clients->detach($client);
    }
    
}

