<?php

namespace Aerys;

interface Websocket {
    public function onOpen($clientId, array $httpRequestEnv);
    public function onData($clientId, $data);
    public function onClose($clientId, $code, $reason);
}
