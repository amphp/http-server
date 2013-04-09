<?php

use Aerys\Handlers\Websocket\SessionFactory,
    Aerys\Handlers\Websocket\SessionManager;

class SessionFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testMakeReturnsNewSession() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        $endpoint = $this->getMock('Aerys\Handlers\Websocket\Endpoint');
        $socket = fopen('php://memory', 'r+');
        $asgiEnv = [];
        
        $sessionFactory = new SessionFactory;
        $session = $sessionFactory->make($socket, $sessMgr, $endpoint, $asgiEnv);
        
        $this->assertInstanceOf('Aerys\Handlers\Websocket\Session', $session);
    }
    
}

