<?php

use Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\EndpointOptions;

class WsExampleChatEndpoint implements Endpoint {
    
    private $clients;
    private $options;
    
    function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->options = new EndpointOptions([
            'debugMode' => TRUE, // Enabled so console will show activity during example execution
        ]);
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
    
    function getOptions() {
        return $this->options;
    }
    
}

