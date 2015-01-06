<?php

namespace Aerys;

interface Websocket {
    public function onStart();
    public function onOpen($clientId, array $httpRequestEnv);
    public function onData($clientId, $data);
    public function onClose($clientId, $code, $reason);
    public function onStop();
}
