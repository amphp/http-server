<?php

namespace Aerys\Websocket;

class Message extends \Amp\ByteStream\Message {
    private $binary = false;

    public function __construct(\Amp\Stream $stream, bool $binary = false) {
        parent::__construct($stream);
        $this->binary = $binary;
    }

    public function isBinary(): bool {
        return $this->binary;
    }
}
