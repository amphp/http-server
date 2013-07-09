<?php

use Aerys\Handlers\Websocket\Client,
    Aerys\Handlers\Websocket\ClientSession,
    Aerys\Handlers\Websocket\Frame,
    Aerys\Handlers\Websocket\WebsocketHandler;

class ClientTest extends PHPUnit_Framework_TestCase {
    
    function testSendTextDelegatesCallToHandler() {
        $session = new ClientSession;
        $reactor = $this->getMock('Amp\Reactor');
        $endpoints = ['/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')];
        $handler = $this->getMock('WebsocketClientTestHandlerMock');
        $handler->expects($this->once())
                ->method('broadcast')
                ->with($session, Frame::OP_TEXT, 42, NULL);
        
        $client = new Client($handler, $session);
        $client->sendText(42);
    }
    
    function testSendBinaryDelegatesCallToHandler() {
        $session = new ClientSession;
        $reactor = $this->getMock('Amp\Reactor');
        $endpoints = ['/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')];
        $handler = $this->getMock('WebsocketClientTestHandlerMock');
        $handler->expects($this->once())
                ->method('broadcast')
                ->with($session, Frame::OP_BIN, 42, NULL);
        
        $client = new Client($handler, $session);
        $client->sendBinary(42);
    }
    
    function testCloseDelegatesCallToHandler() {
        $session = new ClientSession;
        $reactor = $this->getMock('Amp\Reactor');
        $endpoints = ['/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')];
        $handler = $this->getMock('WebsocketClientTestHandlerMock');
        $handler->expects($this->once())
                ->method('close')
                ->with($session, 1005, 'reason');
        
        $client = new Client($handler, $session);
        $client->close(1005, 'reason');
    }
    
    function testGetEnvironmentReturnsDelegateResultFromSession() {
        $session = new ClientSession;
        $session->asgiEnv = 42;
        $reactor = $this->getMock('Amp\Reactor');
        $endpoints = ['/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')];
        $handler = $this->getMock('WebsocketClientTestHandlerMock');
        $client = new Client($handler, $session);
        $this->assertEquals(42, $client->getEnvironment());
    }

    function testGetStatsReturnsDelegateResultFromHandler() {
        $session = new ClientSession;
        $session->asgiEnv = 42;
        $reactor = $this->getMock('Amp\Reactor');
        $endpoints = ['/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')];
        $handler = $this->getMock('WebsocketClientTestHandlerMock');
        $client = new Client($handler, $session);
        $this->assertTrue(is_array($client->getStats()));
    }
    
}

class WebsocketClientTestHandlerMock extends WebsocketHandler {
    function __construct(){}
    function __invoke(array $asgiEnv) {}
    function send($recipients, $opcode, $data, callable $afterSend = NULL){}
    function close($recipients, $code, $reason){}
}
