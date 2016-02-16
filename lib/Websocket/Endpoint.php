<?php

namespace Aerys\Websocket;

use Amp\Promise;

interface Endpoint {
    public function send(/* int|null|array */ $clientId, string $data): Promise;
    public function sendBinary(/* int|null|array */ $clientId, string $data): Promise;
    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = "");
    public function getInfo(int $clientId): array;
    public function getClients(): array;
}
