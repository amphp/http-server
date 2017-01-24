<?php

namespace Aerys\Websocket;

use AsyncInterop\Promise;

class Rfc6455Endpoint implements Endpoint {
    private $gateway;

    public function __construct(Rfc6455Gateway $gateway) {
        $this->gateway = $gateway;
    }

    public function send(string $data, int $clientId): Promise {
        return $this->gateway->send($clientId, $data, false);
    }

    public function sendBinary(string $data, int $clientId): Promise {
        return $this->gateway->send($clientId, $data, true);
    }

    public function broadcast(string $data, array $exceptIds = null): Promise {
        return $this->gateway->broadcast($exceptIds, $data, false);
    }

    public function broadcastBinary(string $data, array $exceptIds = null): Promise {
        return $this->gateway->broadcast($exceptIds, $data, true);
    }

    public function multicast(string $data, array $clientIds = null): Promise {
        return $this->gateway->multicast($clientIds, $data, false);
    }

    public function multicastBinary(string $data, array $clientIds = null): Promise {
        return $this->gateway->multicast($clientIds, $data, true);
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
