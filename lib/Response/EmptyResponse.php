<?php

namespace Aerys\Response;

use Aerys\HttpStatus;
use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class EmptyResponse extends Response {
    public function __construct(array $headers = [], int $code = HttpStatus::NO_CONTENT, string $reason = null) {
        $headers = \array_merge($headers, [
            "content-length" => 0,
        ]);
        parent::__construct(new InMemoryStream, $headers, $code, $reason);
    }
}
