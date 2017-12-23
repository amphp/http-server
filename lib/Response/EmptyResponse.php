<?php

namespace Aerys\Response;

use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class EmptyResponse extends Response {
    public function __construct(array $headers = [], int $code = 200, string $reason = null) {
        $headers = \array_merge($headers, [
            "content-type" => "text/html; charset=utf8",
            "content-length" => 0,
        ]);
        parent::__construct(new InMemoryStream, $headers, $code, $reason);
    }
}
