<?php

namespace Amp\Http\Server;

class MissingAttributeError extends \Error {
    public function __construct(string $message) {
        parent::__construct($message);
    }
}
