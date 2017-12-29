<?php

namespace Aerys\Response;

use Aerys\Response;
use Amp\ByteStream\InMemoryStream;
use const Aerys\HTTP_STATUS;

class RedirectResponse extends Response {
    public function __construct($uri, int $code = HTTP_STATUS["TEMPORARY_REDIRECT"], array $headers = []) {
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
