<?php

namespace Aerys;

use Amp\Promise;

interface WebsocketEndpoint {
    public function send(string $data, int $clientId): Promise;
    public function broadcast(string $data, array $clientIds = null): Promise;
    public function close(int $clientId, int $code, string $reason = ""): Promise;
    public function getInfo(int $clientId): array;
    public function getClients(): array;
}
