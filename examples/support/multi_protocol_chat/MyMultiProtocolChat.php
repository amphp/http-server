<?php

use Amp\Reactor,
    Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Message,
    Aerys\Handlers\Websocket\Endpoint,
    Aerys\Handlers\Websocket\WebsocketHandler;
    
class MyMultiprotocolChat implements Endpoint {
    
    const RECENT_ECHOES_TO_RETAIN = 10;
    const RECENT_ECHOES_PREFIX = '0';
    const USER_COUNT_PREFIX = '1';
    const USER_ECHO_PREFIX = '2';
    
    private $reactor;
    private $websocketHandler;
    private $websocketClients;
    private $lineFeedClients;
    private $recentEchoStack = [];
    private $cachedClientCount = 0;
    private $socketReadGranularity = 32768;
    
    function __construct(Reactor $reactor, WebsocketHandler $websocketHandler) {
        $this->reactor = $reactor;
        $this->lineFeedClients = new \SplObjectStorage;
        $this->websocketClients = new \SplObjectStorage;
        $this->websocketHandler = $websocketHandler;
        
        // Register this object as the endpoint handler for the `/websocket-chat` URI. The only
        // requirement is that we implement the Aerys\Handlers\Websocket\Endpoint interface (we do).
        $this->websocketHandler->registerEndpoint('/echo', $this);
    }
    
    function answerWebsocketUpgradeRequest(array $asgiEnv) {
        // The Aerys websocket handler does everything we need to verify/accept the upgrade
        return $this->websocketHandler->__invoke($asgiEnv);
    }
    
    function answerLineFeedUpgradeRequest(array $asgiEnv) {
        // Return a callable to accept the socket after the upgrade process completes. We could do
        // additional validation of the $asgiEnv here for things like HMAC authorization headers
        // but we won't bother for this simple example. If we return NULL (or any other "falsy"
        // value) ModUpgrade will abort the upgrade attempt and send the client a failure response
        // (426 Upgrade Required) Here we simply return the callback to accept the raw socket when
        // the upgrade response is completed by the Aerys HTTP server.
        return [$this, 'importLineFeedSocket'];
    }
    
    function importLineFeedSocket($socket, array $asgiEnv) {
        $lfClient = new LineFeedClient;
        $lfClient->socket = $socket;
        $lfClient->asgiEnv = $asgiEnv;
        
        // Notify us any time readable data arrives on the socket from this client
        $lfClient->readSubscription = $this->reactor->onReadable($socket, function() use ($lfClient) {
            $this->readDataFromLineFeedClient($lfClient);
        });
        
        $this->lineFeedClients->attach($lfClient);
        $this->cachedClientCount++;
        $this->sendUserCountToWebsocketClients();
    }
    
    private function readDataFromLineFeedClient(LineFeedClient $lfClient) {
        $data = @fread($lfClient->socket, $this->socketReadGranularity);
        
        if ($data || $data === '0') {
            $lfClient->parsableData .= $data;
            $this->parseDataFromLineFeedClient($lfClient);
        } elseif (!is_resource($lfClient->socket) || @feof($lfClient->socket)) {
            // If either of the conditions in the above line evaluate to TRUE we know that the
            // socket connection has been severed.
            $this->closeLineFeedClient($lfClient);
        }
    }
    
    private function parseDataFromLineFeedClient(LineFeedClient $lfClient) {
        while (($eolPos = strpos($lfClient->parsableData, "\n")) !== FALSE) {
            $line = trim(substr($lfClient->parsableData, 0, $eolPos));
            $lfClient->parsableData = substr($lfClient->parsableData, $eolPos + 2);
            
            if ($line !== '') {
                $this->broadcastMessageToLineFeedClients($line, $lfClient);
                
                if (array_unshift($this->recentEchoStack, $line) > self::RECENT_ECHOES_TO_RETAIN) {
                    array_pop($this->recentEchoStack);
                }
                
                $toSend = self::USER_ECHO_PREFIX . $line;
                
                // When we receive a message over the line-feed-protocol we still need to send it to
                // all of our websocket clients ...
                foreach ($this->websocketClients as $wsClient) {
                    $wsClient->sendText($toSend);
                }
            }
        }
        
        if (strlen($lfClient->parsableData) > 1024) {
            $this->closeLineFeedClient($lfClient);
        } 
    }
    
    private function broadcastMessageToLineFeedClients($message, LineFeedClient $author = NULL) {
        if ($author) {
            // Don't send the message to it's author!
            $recipients = clone $this->lineFeedClients;
            $recipients->detach($author);
        } else {
            $recipients = $this->lineFeedClients;
        }
        
        foreach ($recipients as $lfClient) {
            $lfClient->writableData .= "\r\n{$message}";
            $this->writeToLineFeedClient($lfClient);
        }
    }
    
    private function writeToLineFeedClient(LineFeedClient $lfClient) {
        $totalBytesToWrite = strlen($lfClient->writableData);
        $bytesWritten = @fwrite($lfClient->socket, $lfClient->writableData);
        
        if ($bytesWritten >= $totalBytesToWrite) {
            // Write subscriptions should be disabled as soon as all data is written because under
            // normal circumstances sockets are almost always writable. If you fail to disable the
            // subscription after you finish writing you'll max your CPU at 100% because the
            // "onWritable" callback will be invoked continuously by the event reactor.
            $this->disableLineFeedClientWriteSubscription($lfClient);
            $lfClient->writableData = '';
        } elseif ($bytesWritten) {
            // Because our socket stream is non-blocking we may or may not be able to write all the
            // data in one pass. If $bytesWritten is less than the total number of bytes we need to
            // write then we need to enable an "onWritable" subscription for this socket so that the
            // writing will be completed as soon as possible in the future.
            $lfClient->writableData = substr($lfClient->writableData, $bytesWritten);
            $this->enableLineFeedClientWriteSubscription($lfClient);
        } elseif (is_resource($lfClient->socket)) {
            // An fwrite failure will return FALSE, but a live socket can also potentially return
            // 0 (zero) if the write would block, so it's important to verify using is_resource()
            // whether or not the socket connection is alive.
            $this->enableLineFeedClientWriteSubscription($lfClient);
        } else {
            // If we get here while trying to write it means the socket connection has been severed.
            $this->closeLineFeedClient($lfClient);
        }
    }
    
    private function disableLineFeedClientWriteSubscription(LineFeedClient $lfClient) {
        if ($lfClient->writeSubscription) {
            $lfClient->writeSubscription->disable();
        }
    }
    
    private function enableLineFeedClientWriteSubscription(LineFeedClient $lfClient) {
        if ($lfClient->writeSubscription) {
            $lfClient->writeSubscription->enable();
        } else {
            $writeSubscription = $this->reactor->onWritable($lfClient->socket, function() use ($lfClient) {
                $this->writeToLineFeedClient($lfClient);
            });
            $lfClient->writeSubscription = $writeSubscription;
        }
    }
    
    private function closeLineFeedClient(LineFeedClient $lfClient) {
        @fclose($lfClient->socket);
        
        // It's VERY IMPORTANT that you always cancel your socket IO subscriptions once you've
        // finished with them. The event reactor keeps a reference to these subscriptions and if
        // you don't manually cancel them you will create a memory leak. The reactor CANNOT unload
        // these references on it's own. You MUST cancel them or they will pile up over time.
        $lfClient->readSubscription->cancel();
        if ($lfClient->writeSubscription) {
            $lfClient->writeSubscription->cancel();
        }
        
        $this->lineFeedClients->detach($lfClient);
        $this->cachedClientCount--;
        $this->sendUserCountToWebsocketClients();
    }
    
    // -- Websocket functionality --------------------------------------------------------------- //
    // Everything below this point deals with websocket 
    
    function onOpen(Client $wsClient) {
        $this->cachedClientCount++;
        $this->websocketClients->attach($wsClient);
        $this->sendRecentEchoes($wsClient);
        $this->sendUserCountToWebsocketClients();
    }
    
    private function sendRecentEchoes(Client $wsClient) {
        $recentEchoStackJson = json_encode($this->recentEchoStack);
        $msg = self::RECENT_ECHOES_PREFIX . $recentEchoStackJson;
        $wsClient->sendText($msg);
    }
    
    private function sendUserCountToWebsocketClients() {
        $toSend = self::USER_COUNT_PREFIX . $this->cachedClientCount;
        foreach ($this->websocketClients as $wsClient) {
            $wsClient->sendText($toSend);
        }
    }
    
    function onMessage(Client $wsClient, Message $msg) {
        $payload = $msg->getPayload();
        
        if (array_unshift($this->recentEchoStack, $payload) > self::RECENT_ECHOES_TO_RETAIN) {
            array_pop($this->recentEchoStack);
        }
        
        $toSend = self::USER_ECHO_PREFIX . $payload;
        
        foreach ($this->websocketClients as $connectedClient) {
            if ($wsClient !== $connectedClient) {
                $connectedClient->sendText($toSend);
            }
        }
        
        // When we receive a message over the websocket protocol we still need to send it to
        // all of our line-feed-protocol clients ...
        $this->broadcastMessageToLineFeedClients($payload);
    }
    
    function onClose(Client $wsClient, $code, $reason) {
        $this->cachedClientCount--;
        $this->websocketClients->detach($wsClient);
        $this->sendUserCountToWebsocketClients();
    }
}
