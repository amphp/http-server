<?php

namespace Aerys;

use Amp\{ Message, Stream };
use AsyncInterop\Promise;

class FieldBody extends Message {
    private $metadata;

    public function __construct(Stream $stream, Promise $metadata) {
        parent::__construct($stream);
        $this->metadata = $metadata;
    }
    
    public function getMetadata(): Promise {
        return $this->metadata;
    }
}