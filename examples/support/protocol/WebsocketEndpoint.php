<?php

use Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint;

class WebsocketEndpoint implements Endpoint {
    
    const RECENT_ECHOES_PREFIX = '0';
    const USER_COUNT_PREFIX = '1';
    const USER_ECHO_PREFIX = '2';
    
    private $clients;
    private $chatIdClientMap = [];
    private $chatMediator;
    
    function __construct(ChatMediator $chatMediator) {
        $this->chatMediator = $chatMediator;
        
        $chatMediator->subscribe('hello', function($newUserId) {
            $this->onMediatorHello($newUserId);
        });
        
        $chatMediator->subscribe('message', function($authorId, $msg) {
            $this->onMediatorMessage($authorId, $msg);
        });
        
        $chatMediator->subscribe('goodbye', function($disconnectedUserId) {
            $this->onMediatorGoodbye($disconnectedUserId);
        });
        
        $this->clients = new \SplObjectStorage;
    }
    
    // ----------------- REQUIRED WEBSOCKET ENDPOINT METHODS -------------------------------------//
    
    function onOpen(Client $client) {
        $chatId = $this->chatMediator->registerUser();
        
        $this->clients->attach($client, $chatId);
        $this->chatIdClientMap[$chatId] = $client;
    }
    
    function onMessage(Client $client, Message $msg) {
        $payload = $msg->getPayload();
        $chatId = $this->clients->offsetGet($client);
        
        $this->chatMediator->broadcast($chatId, $payload);
    }
    
    function onClose(Client $client, $code, $reason) {
        $chatId = $this->clients->offsetGet($client);
        $this->clients->detach($client);
        unset($this->chatIdClientMap[$chatId]);
        
        $this->chatMediator->disconnect($chatId);
    }
    
    // ---------------- CUSTOM IMPLEMENTATION METHODS BELOW --------------------------------------//
    
    private function onMediatorMessage($chatId, $msg) {
        $recipients = $this->chatIdClientMap;
        
        // Don't send the message to it's author!
        unset($recipients[$chatId]);
        
        $msg = self::USER_ECHO_PREFIX . $msg;
        
        foreach ($recipients as $client) {
            $client->sendText($msg);
        }
    }
    
    private function onMediatorHello($chatId) {
        $this->broadcastUserCount();
        
        if (isset($this->chatIdClientMap[$chatId])) {
            $client = $this->chatIdClientMap[$chatId];
            $this->sendRecentMessagesToClient($client);
        }
    }
    
    private function broadcastUserCount() {
        $toSend = self::USER_COUNT_PREFIX . $this->chatMediator->fetchCount();
        foreach ($this->clients as $client) {
            $client->sendText($toSend);
        }
    }
    
    private function sendRecentMessagesToClient($client) {
        $recentMessages = $this->chatMediator->fetchRecent();
        $recentMessages = json_encode($recentMessages);
        $recentMessages = self::RECENT_ECHOES_PREFIX . $recentMessages;
        $client->sendText($recentMessages);
    }
    
    private function onMediatorGoodbye($chatId) {
        $this->broadcastUserCount();
    }

}
