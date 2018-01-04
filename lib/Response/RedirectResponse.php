<?php

namespace Aerys\Response;

use Aerys\HttpStatus;
use Aerys\Response;
use Amp\ByteStream\InMemoryStream;

class RedirectResponse extends Response {
    public function __construct($uri, int $code = HttpStatus::TEMPORARY_REDIRECT, array $headers = []) {
        parent::__construct(
            new InMemoryStream,
            \array_merge($headers, [
                "location" => $uri,
                "content-length" => 0
            ]),
            $code
        );
    }
}
