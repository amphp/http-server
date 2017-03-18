<?php

namespace Aerys;

use Amp\{ Promise, Stream };

class FieldBody extends Body {
    private $metadata;

    public function __construct(Stream $stream, Promise $metadata) {
        parent::__construct($stream);
        $this->metadata = $metadata;
    }
    
    public function getMetadata(): Promise {
        return $this->metadata;
    }
}