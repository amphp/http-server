<?php

namespace Aerys;

use Amp\ByteStream\InputStream;
use Amp\Promise;

class FieldBody extends Body {
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
