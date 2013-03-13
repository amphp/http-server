<?php

namespace Aerys\Ws;

interface Endpoint {
    
    function onOpen(Client $client);
    function onMessage(Client $client, Message $msg);
    function onClose(Client $client, $code, $reason);
    
    /**
     * @return EndpointOptions
     */
    function getOptions();
}

