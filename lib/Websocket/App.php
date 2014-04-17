<?php

namespace Aerys\Websocket;

interface App {
    public function start(Broker $broker);
    public function onOpen($socketId, array $httpEnvironment);
    public function onData($socketId, $payload, array $context);
    public function onClose($socketId, $code, $reason);
    public function stop();
}
