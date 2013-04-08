<?php

use Aerys\Handlers\Websocket\SessionFacade,
    Aerys\Handlers\Websocket\Session,
    Aerys\Handlers\Websocket\Frame;

class SessionFacadeTest extends PHPUnit_Framework_TestCase {
    
    function testSendDelegatesCallToSessionMethod() {
        $session = $this->getMock('WsSessionFacadeTestSessionStub');
        $session->expects($this->once())
                ->method('addStreamData')
                ->with(42, Frame::OP_TEXT);
        
        $sessionFacade = new SessionFacade($session);
        $sessionFacade->send(42, Frame::OP_TEXT);
    }
    
    function testCloseDelegatesCallToSessionMethod() {
        $session = $this->getMock('WsSessionFacadeTestSessionStub', ['addCloseFrame']);
        $session->expects($this->once())
                ->method('addCloseFrame')
                ->with(1005, 'reason');
        
        $sessionFacade = new SessionFacade($session);
        $sessionFacade->close(1005, 'reason');
    }
    
    function testGetEnvironmentReturnsDelegateResultFromSession() {
        $session = $this->getMock('WsSessionFacadeTestSessionStub', ['getAsgiEnv']);
        $session->expects($this->once())
                ->method('getAsgiEnv')
                ->will($this->returnValue(42));
        
        $sessionFacade = new SessionFacade($session);
        $this->assertEquals(42, $sessionFacade->getEnvironment());
    }
    
    function testGetStatsReturnsDelegateResultFromSession() {
        $session = $this->getMock('WsSessionFacadeTestSessionStub');
        $session->expects($this->once())
                ->method('getStats')
                ->will($this->returnValue(42));
        
        $sessionFacade = new SessionFacade($session);
        $this->assertEquals(42, $sessionFacade->getStats());
    }
}

class WsSessionFacadeTestSessionStub extends Session {
    function __construct() {}
}

