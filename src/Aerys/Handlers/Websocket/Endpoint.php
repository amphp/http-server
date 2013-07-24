<?php

namespace Aerys\Handlers\Websocket;

interface Endpoint {
    
    function onOpen($socketId);
    function onMessage($socketId, Message $msg);
    function onClose($socketId, $code, $reason);
    
}
