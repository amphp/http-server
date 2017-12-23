<?php

namespace Aerys\Response;

use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class TextResponse extends Response {
    public function __construct(string $text, array $headers = [], int $code = 200, string $reason = null) {
        $headers = \array_merge($headers, [
            "content-type" => "text/plain; charset=utf8",
            "content-length" => \strlen($text),
        ]);
        parent::__construct(new InMemoryStream($text), $headers, $code, $reason);
    }
}
