<?php

namespace Aerys;

use Amp\Promise;

class FieldBody extends Body {
    private $metadata;
    private $valid;
    
    public function __construct(Promise $promise, Promise $metadata) {
        parent::__construct($promise);
        $this->metadata = $metadata;
        $this->valid = $this->valid();
    }
    
    public function defined(): Promise {
        return $this->valid;
    }
    
    public function getMetadata(): Promise {
        return $this->metadata;
    }
}