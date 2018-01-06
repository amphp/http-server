<?php

namespace Aerys\Response;

use Aerys\HttpStatus;
use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class TextResponse extends Response {
    public function __construct(string $text, array $headers = [], int $code = HttpStatus::OK, string $reason = null) {
        $headers = \array_merge($headers, [
            "content-type" => "text/plain; charset=utf-8",
            "content-length" => \strlen($text),
        ]);
        parent::__construct(new InMemoryStream($text), $headers, $code, $reason);
    }
}
