<?php

namespace Aerys\Response;

use Aerys\HttpStatus;
use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class HtmlResponse extends Response {
    public function __construct(string $html, array $headers = [], int $code = HttpStatus::OK, string $reason = null) {
        $headers = \array_merge($headers, [
            "content-type" => "text/html; charset=utf-8",
            "content-length" => \strlen($html),
        ]);
        parent::__construct(new InMemoryStream($html), $headers, $code, $reason);
    }
}
