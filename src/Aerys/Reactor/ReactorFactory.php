<?php

namespace Aerys\Reactor;

class ReactorFactory {
    
    /**
     * @TODO select best available event base for the current system
     */
    function select() {
        return new LibEventReactor;
    }
    
}
