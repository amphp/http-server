<?php

namespace Aerys\Internal;

use Amp\Struct;

class Response {
    use Struct;

    /** @var int */
    public $status = 200;
    /** @var string */
    public $reason = "OK";
    /** @var string[][] */
    public $headers = [];
    /** @var string[] */
    public $push = [];
    /** @var \Amp\ByteStream\InputStream|null */
    public $body;
    /** @var callable|null */
    public $detach;
}
