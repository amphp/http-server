<?php

namespace Amp\Http\Server\Websocket;

use Amp\Http\Server\Body;
use Amp\ByteStream\InputStream;

final class Message extends Body {
    /** @var bool */
    private $binary;

    public function __construct(InputStream $stream, bool $binary) {
        parent::__construct($stream);

        $this->binary = $binary;
    }

    /**
     * @return bool Returns a promise that resolves to true if the message is binary, false if it is UTF-8 text.
     */
    public function isBinary(): bool {
        return $this->binary;
    }
}
