<?php

use Aerys\Handlers\Websocket\WebsocketHandler,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint;

class WebsocketEndpoint implements Endpoint {
    
    const RECENT_ECHOES_PREFIX = '0';
    const USER_COUNT_PREFIX = '1';
    const USER_ECHO_PREFIX = '2';
    
    private $websocketHandler;
    private $chatMediator;
    private $socketIdToChatIdMap = [];
    private $chatIdToSocketIdMap = [];
    
    function __construct(WebsocketHandler $websocketHandler, ChatMediator $chatMediator) {
        $this->websocketHandler = $websocketHandler;
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
    }
    
    // ----------------- REQUIRED WEBSOCKET ENDPOINT METHODS -------------------------------------//
    
    function onOpen($socketId) {
        $chatId = $this->chatMediator->registerUser();
        $this->socketIdToChatIdMap[$socketId] = $chatId;
        $this->chatIdToSocketIdMap[$chatId] = $socketId;
        
        $this->broadcastUserCount($socketId);
    }
    
    function onMessage($socketId, Message $msg) {
        $payload = $msg->getPayload();
        $chatId = $this->socketIdToChatIdMap[$socketId];
        
        $this->chatMediator->broadcast($chatId, $payload);
    }
    
    function onClose($socketId, $code, $reason) {
        $chatId = $this->socketIdToChatIdMap[$socketId];
        
        unset(
            $this->socketIdToChatIdMap[$socketId],
            $this->chatIdToSocketIdMap[$chatId]
        );
        
        $this->chatMediator->disconnect($chatId);
    }
    
    // ---------------- CUSTOM IMPLEMENTATION METHODS BELOW --------------------------------------//
    
    private function onMediatorMessage($msgAuthorChatId, $msg) {
        $recipients = $this->chatIdToSocketIdMap;
        
        // Don't send the message to it's author!
        unset($recipients[$msgAuthorChatId]);
        
        $recipients = array_values($recipients);
        
        $msg = self::USER_ECHO_PREFIX . $msg;
        $this->websocketHandler->sendText($recipients, $msg);
    }
    
    private function onMediatorHello($chatIdThatConnected) {
        $this->broadcastUserCount();
        
        if (isset($this->chatIdToSocketIdMap[$chatIdThatConnected])) {
            $socketId = $this->chatIdToSocketIdMap[$chatIdThatConnected];
            $recentMessages = $this->chatMediator->fetchRecent();
            $recentMessages = json_encode($recentMessages);
            $recentMessages = self::RECENT_ECHOES_PREFIX . $recentMessages;
            $this->websocketHandler->sendText($socketId, $recentMessages);
        }
    }
    
    private function broadcastUserCount($recipients = NULL) {
        $msg = self::USER_COUNT_PREFIX . $this->chatMediator->fetchCount();
        $recipients = $recipients ?: array_values($this->chatIdToSocketIdMap);
        $this->websocketHandler->sendText($recipients, $msg);
    }
    
    private function onMediatorGoodbye($chatIdThatDisconnected) {
        $this->broadcastUserCount();
    }

}
