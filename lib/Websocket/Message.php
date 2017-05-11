<?php

namespace Aerys\Websocket;

class Message extends \Amp\ByteStream\Message {
    private $binary = false;

    public function __construct(\Amp\Iterator $iterator, bool $binary = false) {
        parent::__construct($iterator);
        $this->binary = $binary;
    }

    public function isBinary(): bool {
        return $this->binary;
    }
}
