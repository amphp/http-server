<?php

namespace Aerys\Ws;

interface WampEndpoint extends Endpoint {
    
    // The client has made an RPC to the server. You should send a callResult or callError in return
    function onCall(Client $client, string $id, Topic $topic, array $params);
    
    // The client has subscribed to a channel, expecting to receive events published to the given $topic
    function onSubscribe(Client $client, Topic $topic);
    
    // The client unsubscribed from a channel, opting out of receiving events from the $topic
    function onUnsubscribe(Client $client, Topic $topic);
    
    // The user publishes data to a $topic. You should return an Event Command to Connections who have Subscribed to the $topic
    function onPublish(Client $client, Topic $topic, string $event);
    
}

