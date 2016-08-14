<?php

namespace Aerys\Websocket;

use Interop\Async\Awaitable;

interface Endpoint {
    public function send(/* int|null|array */ $clientId, string $data): Awaitable;
    public function sendBinary(/* int|null|array */ $clientId, string $data): Awaitable;
    public function close(int $clientId, int $code = Code::NORMAL_CLOSE, string $reason = "");
    public function getInfo(int $clientId): array;
    public function getClients(): array;
}
