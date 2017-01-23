<?php

namespace Aerys\Websocket;

use AsyncInterop\Promise;

class Rfc6455Endpoint implements Endpoint {
    private $gateway;

    public function __construct(Rfc6455Gateway $gateway) {
        $this->gateway = $gateway;
    }

    public function send($clientId, string $data): Promise {
        return $this->gateway->send($clientId, $data, false);
    }

    public function sendBinary($clientId, string $data): Promise {
        return $this->gateway->send($clientId, $data, true);
    }

    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = ""): Promise {
        return $this->gateway->close($clientId, $code, $reason);
    }

    public function getInfo(int $clientId): array {
        return $this->gateway->getInfo($clientId);
    }

    public function getClients(): array {
        return $this->gateway->getClients();
    }
}
