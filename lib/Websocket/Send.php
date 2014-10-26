<?php

namespace Aerys\Websocket;

final class Send {
    private $data;
    private $clientId;

    public function __construct($data, $clientId) {
        $this->data = $data;
        $this->clientId = $clientId;
    }

    public function getData() {
        return $this->data;
    }

    public function getClientId() {
        return $this->clientId;
    }

    public function toArray() {
        return [$this->data, [$this->clientId], []];
    }
}
