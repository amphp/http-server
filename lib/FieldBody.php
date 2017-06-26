<?php

namespace Aerys;

use Amp\ByteStream\IteratorStream;
use Amp\Iterator;
use Amp\Promise;

class FieldBody extends Body {
    private $metadata;

    public function __construct(Iterator $iterator, Promise $metadata) {
        parent::__construct(new IteratorStream($iterator));
        $this->metadata = $metadata;
    }

    public function getMetadata(): Promise {
        return $this->metadata;
    }
}
