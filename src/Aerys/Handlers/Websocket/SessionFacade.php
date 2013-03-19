<?php

namespace Aerys\Handlers\Websocket;

class SessionFacade {
    
    private $session;
    
    function __construct(Session $session) {
        $this->session = $session;
    }
    
    function send($data, $opcode) {
        $this->session->addStreamData($data, $opcode);
    }
    
    function close($code = Codes::NORMAL_CLOSE, $reason = '') {
        $this->session->addCloseFrame($code, $reason);
    }
    
    function getEnvironment() {
        return $this->session->getAsgiEnv();
    }
    
    function getStats() {
        return $this->session->getStats();
    }
    
}

