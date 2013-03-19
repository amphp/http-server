<?php

namespace Aerys\Handlers\Websocket;

class ClientFactory {

    function __invoke(SessionFacade $sessionFacade) {
        return new Client($sessionFacade);
    }
    
}

