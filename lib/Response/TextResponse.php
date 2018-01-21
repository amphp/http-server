<?php

namespace Aerys\Response;

use Aerys\Response;
use Amp\ByteStream\InMemoryStream;
use Amp\Http\Status;

class TextResponse extends Response {
    public function __construct(string $text, array $headers = [], int $code = Status::OK, string $reason = null) {
        $headers = \array_merge($headers, [
            "content-type" => "text/plain; charset=utf-8",
            "content-length" => \strlen($text),
        ]);
        parent::__construct(new InMemoryStream($text), $headers, $code, $reason);
    }
}
