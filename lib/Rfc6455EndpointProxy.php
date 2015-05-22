<?php

namespace Aerys;

use Amp\Promise;

class Rfc6455EndpointProxy implements WebsocketEndpoint {
    private $endpoint;
    public function __construct(Rfc6455Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }
    public function send(string $data, int $clientId): Promise {
        return $this->endpoint->send($data, $clientId);
    }
    public function broadcast(string $data, array $clientIds = null): Promise {
        return $this->endpoint->broadcast($data, $clientIds);
    }
    public function close(int $clientId, int $code, string $reason = ""): Promise {
        return $this->endpoint->close($clientId, $code, $reason);
    }
    public function getInfo(int $clientId): array {
        return $this->endpoint->getInfo($clientId);
    }
    public function getClients(): array {
        return $this->endpoint->getClients();
    }
}
