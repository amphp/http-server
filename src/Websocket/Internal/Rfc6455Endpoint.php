<?php

namespace Amp\Http\Server\Websocket\Internal;

use Amp\Http\Server\Websocket\Code;
use Amp\Http\Server\Websocket\Endpoint;
use Amp\Promise;

class Rfc6455Endpoint implements Endpoint {
    /** @var \Amp\Http\Server\Websocket\Internal\Rfc6455Gateway */
    private $gateway;

    public function __construct(Rfc6455Gateway $gateway) {
        $this->gateway = $gateway;
    }

    public function send(string $data, int $clientId): Promise {
        return $this->gateway->send($data, false, $clientId);
    }

    public function sendBinary(string $data, int $clientId): Promise {
        return $this->gateway->send($data, true, $clientId);
    }

    public function broadcast(string $data, array $exceptIds = []): Promise {
        return $this->gateway->broadcast($data, false, $exceptIds);
    }

    public function broadcastBinary(string $data, array $exceptIds = []): Promise {
        return $this->gateway->broadcast($data, true, $exceptIds);
    }

    public function multicast(string $data, array $clientIds): Promise {
        return $this->gateway->multicast($data, false, $clientIds);
    }

    public function multicastBinary(string $data, array $clientIds): Promise {
        return $this->gateway->multicast($data, true, $clientIds);
    }

    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = "") {
        $this->gateway->close($clientId, $code, $reason);
    }

    public function getInfo(int $clientId): array {
        return $this->gateway->getInfo($clientId);
    }

    public function getClients(): array {
        return $this->gateway->getClients();
    }
}
