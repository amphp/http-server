<?php

use Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\WebsocketHandler;

class EchoEndpoint implements Endpoint {
    
    const RECENT_MSG_LIMIT = 10;
    const RECENT_MSG_PREFIX = '0';
    const USER_COUNT_PREFIX = '1';
    const USER_ECHO_PREFIX = '2';
    
    private $websocketHandler;
    private $sockets = [];
    private $recentMessages = [];
    
    function __construct(WebsocketHandler $websocketHandler) {
        $this->websocketHandler = $websocketHandler;
    }
    
    function onOpen($socketId) {
        $this->sockets[$socketId] = $socketId;
        $this->broadcastUserCount();
        $this->sendUserRecentMessages($socketId);
    }
    
    private function sendUserRecentMessages($socketId) {
        $recipient = $socketId;
        $msg = self::RECENT_MSG_PREFIX . json_encode($this->recentMessages);
        $this->websocketHandler->sendText($recipient, $msg);
    }
    
    private function broadcastUserCount() {
        $recipients = array_values($this->sockets);
        $msg = self::USER_COUNT_PREFIX . count($this->sockets);
        $this->websocketHandler->sendText($recipients, $msg);
    }
    
    function onMessage($socketId, Message $msg) {
        $payload = $msg->getPayload();
        
        // Only keep the last N messages in memory
        if (array_unshift($this->recentMessages, $payload) > self::RECENT_MSG_LIMIT) {
            array_pop($this->recentMessages);
        }
        
        $recipients = $this->sockets;
        
        // Don't send this message to the client that originated it!
        unset($recipients[$socketId]);
        
        $msg = self::USER_ECHO_PREFIX . $payload;
        $this->websocketHandler->sendText($recipients, $msg);
    }
    
    function onClose($socketId, $code, $reason) {
        unset($this->sockets[$socketId]);
        $this->broadcastUserCount();
    }

}
