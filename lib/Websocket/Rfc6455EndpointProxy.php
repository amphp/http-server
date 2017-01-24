<?php

namespace Aerys\Websocket;

use Amp\Promise;

class Rfc6455EndpointProxy implements Endpoint {
    private $endpoint;

    public function __construct(Rfc6455Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    public function send(string $data, int $clientId): Promise {
        return $this->endpoint->send($data, false, $clientId);
    }

    public function sendBinary(string $data, int $clientId): Promise {
        return $this->endpoint->send($data, true, $clientId);
    }

    public function broadcast(string $data, array $exceptIds = []): Promise {
        return $this->endpoint->broadcast($data, false, $exceptIds);
    }

    public function broadcastBinary(string $data, array $exceptIds = []): Promise {
        return $this->endpoint->broadcast($data, true, $exceptIds);
    }

    public function multicast(string $data, array $clientIds): Promise {
        return $this->endpoint->multicast($data, false, $clientIds);
    }

    public function multicastBinary(string $data, array $clientIds): Promise {
        return $this->endpoint->multicast($data, true, $clientIds);
    }
    
    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = "") {
        $this->endpoint->close($clientId, $code, $reason);
    }

    public function getInfo(int $clientId): array {
        return $this->endpoint->getInfo($clientId);
    }

    public function getClients(): array {
        return $this->endpoint->getClients();
    }
}
