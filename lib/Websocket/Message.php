<?php

namespace Aerys\Websocket;

use Amp\ByteStream\IteratorStream;

class Message extends \Amp\ByteStream\Message {
    private $binary = false;

    public function __construct(\Amp\Iterator $iterator, bool $binary = false) {
        parent::__construct(new IteratorStream($iterator));
        $this->binary = $binary;
    }

    public function isBinary(): bool {
        return $this->binary;
    }
}
