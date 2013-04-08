<?php

use Aerys\Handlers\Websocket\ClientFactory,
    Aerys\Handlers\Websocket\SessionFacade;

class ClientFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testSendTextDelegatesCallToSessionFacade() {
        $sessionFacade = $this->getMock('WsClientFactoryTestSessionFacadeStub');
        
        $clientFactory = new ClientFactory;
        $this->assertInstanceOf('Aerys\Handlers\Websocket\Client', $clientFactory->__invoke($sessionFacade));
    }
}

class WsClientFactoryTestSessionFacadeStub extends SessionFacade {
    function __construct() {}
}
