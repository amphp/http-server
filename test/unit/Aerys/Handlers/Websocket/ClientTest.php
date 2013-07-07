<?php

use Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\Frame;

class ClientTest extends PHPUnit_Framework_TestCase {
    
    function testSendTextDelegatesCallToSessionFacade() {
        $this->markTestSkipped();
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['send']);
        $sessionFacade->expects($this->once())
                      ->method('send')
                      ->with(42, Frame::OP_TEXT);
        
        $client = new Client($sessionFacade);
        $client->sendText(42);
    }
    
    function testSendBinaryDelegatesCallToSessionFacade() {
        $this->markTestSkipped();
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['send']);
        $sessionFacade->expects($this->once())
                      ->method('send')
                      ->with(42, Frame::OP_BIN);
        
        $client = new Client($sessionFacade);
        $client->sendBinary(42);
    }
    
    function testCloseDelegatesCallToSessionFacade() {
        $this->markTestSkipped();
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['close']);
        $sessionFacade->expects($this->once())
                      ->method('close')
                      ->with(1005, 'reason');
        
        $client = new Client($sessionFacade);
        $client->close(1005, 'reason');
    }
    
    function testGetEnvironmentReturnsDelegateResultFromSessionFacade() {
        $this->markTestSkipped();
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['getEnvironment']);
        $sessionFacade->expects($this->once())
                      ->method('getEnvironment')
                      ->will($this->returnValue(42));
        
        $client = new Client($sessionFacade);
        $this->assertEquals(42, $client->getEnvironment());
    }
    
    function testGetStatsReturnsDelegateResultFromSessionFacade() {
        $this->markTestSkipped();
        $sessionFacade = $this->getMock('WsClientTestSessionFacadeStub', ['getStats']);
        $sessionFacade->expects($this->once())
                      ->method('getStats')
                      ->will($this->returnValue(42));
        
        $client = new Client($sessionFacade);
        $this->assertEquals(42, $client->getStats());
    }
}

