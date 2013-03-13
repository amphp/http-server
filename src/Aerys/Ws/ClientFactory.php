<?php

namespace Aerys\Ws;

class ClientFactory {

    function __invoke(SessionFacade $sessionFacade) {
        return new Client($sessionFacade);
    }
    
}

