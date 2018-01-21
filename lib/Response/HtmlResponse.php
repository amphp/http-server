<?php

namespace Aerys\Response;

use Aerys\Response;
use Amp\ByteStream\InMemoryStream;
use Amp\Http\Status;

class HtmlResponse extends Response {
    public function __construct(string $html, array $headers = [], int $code = Status::OK, string $reason = null) {
        $headers = \array_merge($headers, [
            "content-type" => "text/html; charset=utf-8",
            "content-length" => \strlen($html),
        ]);
        parent::__construct(new InMemoryStream($html), $headers, $code, $reason);
    }
}
