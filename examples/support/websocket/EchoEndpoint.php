<?php

use Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint;

class EchoEndpoint implements Endpoint {
    
    const RECENT_ECHOES_TO_RETAIN = 10;
    const RECENT_ECHOES_PREFIX = '0';
    const USER_COUNT_PREFIX = '1';
    const USER_ECHO_PREFIX = '2';
    
    private $clients;
    private $recentEchoStack = [];
    
    function __construct() {
        $this->clients = new \SplObjectStorage;
    }
    
    /**
     * Endpoint::onOpen() is invoked each time a client connects to the endpoint
     * 
     * @param Client $client The client that just connected
     */
    function onOpen(Client $client) {
        $this->clients->attach($client);
        $this->sendRecentEchoes($client);
        $this->sendUserCount();
    }
    
    /**
     * Send the newly connected client the list of recent messages
     * 
     * @param Client $client The client that just connected
     */
    private function sendRecentEchoes(Client $client) {
        $recentEchoStackJson = json_encode($this->recentEchoStack);
        $msg = self::RECENT_ECHOES_PREFIX . $recentEchoStackJson;
        $client->sendText($msg);
    }
    
    /**
     * Send all connected users an updated client count
     */
    private function sendUserCount() {
        $toSend = self::USER_COUNT_PREFIX . $this->clients->count();
        foreach ($this->clients as $client) {
            $client->sendText($toSend);
        }
    }
    
    /**
     * Endpoint::onMessage() is invoked any time our endpoint receives a message from a client
     * 
     * All we're doing in this endpoint is echoing out new messages to all connected clients. This
     * function adds the new message to the top of the stack and updates all current clients with
     * this latest information.
     * 
     * @param Client $client The client that sent us this message
     * @param Message $msg The message we just received from the client
     */
    function onMessage(Client $client, Message $msg) {
        $payload = $msg->getPayload();
        
        if (array_unshift($this->recentEchoStack, $payload) > self::RECENT_ECHOES_TO_RETAIN) {
            array_pop($this->recentEchoStack);
        }
        
        $toSend = self::USER_ECHO_PREFIX . $payload;
        
        foreach ($this->clients as $connectedClient) {
            // Don't send this message to the client that originated it
            if ($client !== $connectedClient) {
                $connectedClient->sendText($toSend);
            }
        }
    }
    
    /**
     * Endpoint::onClose() is called each time a client connection to this endpoint is closed
     * 
     * This endpoint simply detaches the client object in question from the local storage object
     * and updates all connected clients with the new count of connected users.
     * 
     * @param Client $client The client whose connection ended
     * @param int $code The websocket close code describing the reason for the disconnection
     * @param string $reason A description of the circumstances surrounding the close (if given)
     */
    function onClose(Client $client, $code, $reason) {
        $this->clients->detach($client);
        $this->sendUserCount();
    }

}
