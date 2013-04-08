<?php

use Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Frame,
    Aerys\Handlers\Websocket\SessionFacade;

class ClientTest extends PHPUnit_Framework_TestCase {
    
    function testSendTextDelegatesCallToSessionFacade() {
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['send']);
        $sessionFacade->expects($this->once())
                      ->method('send')
                      ->with(42, Frame::OP_TEXT);
        
        $client = new Client($sessionFacade);
        $client->sendText(42);
    }
    
    function testSendBinaryDelegatesCallToSessionFacade() {
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['send']);
        $sessionFacade->expects($this->once())
                      ->method('send')
                      ->with(42, Frame::OP_BIN);
        
        $client = new Client($sessionFacade);
        $client->sendBinary(42);
    }
    
    function testCloseDelegatesCallToSessionFacade() {
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['close']);
        $sessionFacade->expects($this->once())
                      ->method('close')
                      ->with(1005, 'reason');
        
        $client = new Client($sessionFacade);
        $client->close(1005, 'reason');
    }
    
    function testGetEnvironmentReturnsDelegateResultFromSessionFacade() {
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['getEnvironment']);
        $sessionFacade->expects($this->once())
                      ->method('getEnvironment')
                      ->will($this->returnValue(42));
        
        $client = new Client($sessionFacade);
        $this->assertEquals(42, $client->getEnvironment());
    }
    
    function testGetStatsReturnsDelegateResultFromSessionFacade() {
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['getStats']);
        $sessionFacade->expects($this->once())
                      ->method('getStats')
                      ->will($this->returnValue(42));
        
        $client = new Client($sessionFacade);
        $this->assertEquals(42, $client->getStats());
    }
}

class WsClientTestSessionFacadeStub extends SessionFacade {
    function __construct() {}
}

