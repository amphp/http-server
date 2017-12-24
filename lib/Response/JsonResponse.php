<?php

namespace Aerys\Response;

use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class JsonResponse extends Response {
    public function __construct(string $json, array $headers = [], int $code = 200, string $reason = null) {
        $headers = \array_merge($headers, ["content-type" => "application/json"]);
        parent::__construct(new InMemoryStream($json), $headers, $code, $reason);
    }
}
