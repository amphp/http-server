<?php

namespace Aerys\Engine;

class LibEventSubscription extends Subscription {
    
    /**
     * The libevent event resource must be stored to avoid garbage collection
     */
    private $event;
    
    function __construct(EventBase $base, $event) {
        parent::__construct($base);
        $this->event = $event;
    }
    
}

