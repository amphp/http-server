<?php

namespace Aerys\Response;

use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class TextResponse extends Response {
    public function __construct(string $html, array $headers = [], int $code = 200, string $reason = null) {
        $headers = \array_merge($headers, ["content-type" => "text/plain; charset=utf8"]);
        parent::__construct(new InMemoryStream($html), $headers, $code, $reason);
    }
}
