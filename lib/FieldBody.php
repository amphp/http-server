<?php

namespace Aerys;

use Amp\{ Promise, Iterator };

class FieldBody extends Body {
    private $metadata;

    public function __construct(Iterator $iterator, Promise $metadata) {
        parent::__construct($iterator);
        $this->metadata = $metadata;
    }
    
    public function getMetadata(): Promise {
        return $this->metadata;
    }
}