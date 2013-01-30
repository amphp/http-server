<?php

namespace Aerys\Engine;

class Subscription {

    private $base;
    private $callback;
    
    function __construct(EventBase $base) {
        $this->base = $base;
    }
    
    function cancel() {
        $this->base->cancel($this);
    }
    
}

