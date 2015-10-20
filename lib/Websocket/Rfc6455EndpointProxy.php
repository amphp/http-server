<?php

namespace Aerys\Websocket;

use Amp\Promise;

class Rfc6455EndpointProxy implements Endpoint {
    private $endpoint;

    public function __construct(Rfc6455Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    public function send($clientId, string $data): Promise {
        return $this->endpoint->send($clientId, $data);
    }

    public function sendBinary($clientId, string $data): Promise {
        return $this->endpoint->send($clientId, $data, $binary = true);
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
