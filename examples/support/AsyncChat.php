<?php // websocket endpoint

use Aerys\Handlers\Websocket\Codes,
    Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\EndpointOptions,
    Amp\Async\Dispatcher,
    Amp\Async\CallResult;

class AsyncChat implements Endpoint {
    
    private $clients;
    private $asyncDispatcher;
    
    function __construct(Dispatcher $dispatcher) {
        $this->asyncDispatcher = $dispatcher;
        $this->clients = new \SplObjectStorage;
    }
    
    function onOpen(Client $client) {
        $this->clients->attach($client);
        $this->loadRecentMessages($client);
    }
    
    function onMessage(Client $client, Message $msg) {
        $this->storeChatMessage($client, $msg->getPayload());
    }
    
    function onClose(Client $client, $code, $reason) {
        $this->clients->detach($client);
    }
    
    // -------------------- ASYNC FUNCTIONALITY FOLLOWS --------------------------
    
    private function loadRecentMessages(Client $client) {
        $onResult = function(CallResult $callResult) use ($client) {
            $this->onRecentMessagesLoadResult($callResult, $client);
        };
        $this->asyncDispatcher->call($onResult, 'loadRecentMessages'/*, $varArgs = NULL*/);
    }
    
    private function onRecentMessageLoadResult(CallResult $callResult, Client $client) {
        if ($callResult->isSuccess()) {
            $recentMessages = $callResult->getResult();
            $client->sendText($recentMessages);
        } else {
            // This example closes the client ws:// connection, but you could just as easily send
            // your downstream .js app a message along the lines of "failed to load recent messages,
            // please refresh your page to try again" (or something similar).
            $client->close(Codes::UNEXPECTED_SERVER_ERROR);
        }
    }
    
    private function storeChatMessage(Client $client, $chatMsg) {
        $onResult = function(CallResult $callResult) use ($client) {
            $this->onStorageResult($callResult, $client);
        };
        $this->asyncDispatcher->call($onResult, 'storeChatMessageInDb', $chatMsg);
    }
    
    private function onStorageResult(CallResult $callResult, Client $client) {
        if ($callResult->isSuccess()) {
            // Our chat message was saved to the db! Now we can send it out to the other clients ...
            foreach ($this->clients as $c) {
                if ($client !== $c) {
                    $c->sendText($payload);
                }
            }
        } else {
            // This example closes the client ws:// connection, but you could just as easily send
            // your downstream .js app a message along the lines of "failed to save message, click
            // here to retry" (or something similar).
            $client->close(Codes::UNEXPECTED_SERVER_ERROR);
        }
    }
}














































