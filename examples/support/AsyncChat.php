<?php 

use Aerys\Handlers\Websocket\Codes,
    Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint,
    Amp\MultiProcess\PhpDispatcher,
    Amp\MultiProcess\CallResult;

class AsyncChat implements Endpoint {
    
    private $dispatcher;
    private $clients;
    
    function __construct(PhpDispatcher $dispatcher) {
        $this->dispatcher = $dispatcher;
        $this->clients = new \SplObjectStorage;
    }
    
    function onOpen(Client $client) {
        $this->clients->attach($client);
        $this->loadMessages($client);
    }
    
    function onMessage(Client $client, Message $msg) {
        $this->saveMessage($client, $msg->getPayload());
    }
    
    function onClose(Client $client, $code, $reason) {
        $this->clients->detach($client);
    }
    
    // ---------------------------------- ASYNC FUNCTIONALITY --------------------------------------
    // All websocket actions happen inside the server's non-blocking event loop. Any slow operation
    // will totally hose your server's performance. It's IMPERATIVE that you execute IO operations
    // without blocking. In the code below we use the Amp multiprocess dispatcher to asynchronously
    // store and retrieve messages from the database without blocking the main process.
    // ---------------------------------------------------------------------------------------------
    
    private function loadMessages(Client $client) {
        $onResult = function(CallResult $r) use ($client) { $this->onMessageLoad($r, $client); };
        $this->dispatcher->call($onResult, 'loadMessages', 50);
    }
    
    private function onMessageLoad(CallResult $r, Client $client) {
        if ($r->isSuccess()) {
            $recentMessages = $r->getResult();
            $client->sendText($recentMessages);
        } else {
            // This example closes the client ws:// connection, but you could just as easily send
            // your downstream .js app a message like "failed to load recent messages,
            // please refresh your page to try again."
            $client->close(Codes::UNEXPECTED_SERVER_ERROR);
        }
    }
    
    private function saveMessage(Client $client, $chatMsg) {
        $onResult = function(CallResult $r) use ($client, $chatMsg) { $this->onMessageSave($r, $client, $chatMsg); };
        $this->dispatcher->call($onResult, 'saveMessage', $chatMsg);
    }
    
    private function onMessageSave(CallResult $r, Client $client, $chatMsg) {
        if ($r->isSuccess()) {
            // Our chat message was saved to the db! Now we can send it out to the other clients ...
            foreach ($this->clients as $c) {
                if ($client !== $c) {
                    $c->sendText($chatMsg);
                }
            }
        } else {
            // This example closes the client ws:// connection, but you could also send your downstream
            // client a message along the lines of "failed to save message, please try again."
            $client->close(Codes::UNEXPECTED_SERVER_ERROR);
        }
    }
    
}

