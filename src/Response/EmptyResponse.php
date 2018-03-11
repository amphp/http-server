<?php

namespace Amp\Http\Server\Response;

use Amp\Http\Server\Response;
use Amp\ByteStream\InMemoryStream;
use Amp\Http\Status;

class EmptyResponse extends Response {
    public function __construct(array $headers = [], int $code = Status::NO_CONTENT, string $reason = null) {
        $headers = \array_merge($headers, [
            "content-length" => 0,
        ]);
        parent::__construct(new InMemoryStream, $headers, $code, $reason);
    }
}
