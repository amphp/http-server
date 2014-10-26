<?php

namespace Aerys\Websocket;

final class Close {
    private $clientId;
    private $code;
    private $reason;

    public function __construct($clientId, $code = null, $reason = null) {
        $this->clientId = $clientId;
        $this->code = $code;
        $this->reason = $reason;
    }

    public function getClientId() {
        return $this->clientId;
    }

    public function toArray() {
        return [$this->clientId, $this->code, $this->reason];
    }
}
