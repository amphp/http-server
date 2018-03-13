<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\Payload;
use Amp\Promise;

final class FieldBody extends Payload {
    /** @var string */
    private $name;

    /** @var \Amp\Promise */
    private $metadata;

    public function __construct(string $name, InputStream $stream, Promise $metadata) {
        parent::__construct($stream);
        $this->name = $name;
        $this->metadata = $metadata;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getMetadata(): Promise {
        return $this->metadata;
    }
}
