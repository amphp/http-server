<?php

namespace Aerys\Websocket;

use Amp\Promise;

class Rfc6455EndpointProxy implements Endpoint {
    private $endpoint;

    public function __construct(Rfc6455Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    public function send(int $clientId, string $data): Promise {
        return $this->endpoint->send($clientId, $data);
    }

    public function broadcast(string $data, array $clientIds = null): Promise {
        return $this->endpoint->broadcast($data, $clientIds);
    }

    public function close(int $clientId, int $code = CODES["NORMAL_CLOSE"], string $reason = "") {
        $this->endpoint->close($clientId, $code, $reason);
    }

    public function getInfo(int $clientId): array {
        return $this->endpoint->getInfo($clientId);
    }

    public function getClients(): array {
        return $this->endpoint->getClients();
    }
}
