<?php

namespace Aerys\Websocket;

use Amp\Promise;

interface Endpoint {
    public function send(int $clientId, string $data): Promise;
    public function sendBinary(int $clientId, string $data): Promise;
    public function broadcast(string $data, array $clientIds = null): Promise;
    public function broadcastBinary(string $data, array $clientIds = null): Promise;
    public function close(int $clientId, int $code = CODES["NORMAL_CLOSE"], string $reason = "");
    public function getInfo(int $clientId): array;
    public function getClients(): array;
}
