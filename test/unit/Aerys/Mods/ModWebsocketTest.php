<?php

use Aerys\Mods\ModWebsocket,
    Aerys\Handlers\Websocket\Handler;

class ModWebsocketTest extends PHPUnit_Framework_TestCase {
    
    function testOnRequestDelegatesCallsToWebsocketHandler() {
        $reactor = $this->getMock('Amp\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        $wsHandler = $this->getMock('StubWebsocketHandler', ['__invoke']);
        
        $requestId = 42;
        $fakeAsgiEnv = ['normally this value would be an ASGI environment array'];
        $fakeAsgiResponse = ['this would usually be [$status, $reason, $headers, $body]'];
        
        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($fakeAsgiEnv));
        
        $server->expects($this->once())
               ->method('setResponse')
               ->with($requestId, $fakeAsgiResponse);
        
        $wsHandler->expects($this->once())
                  ->method('__invoke')
                  ->with($fakeAsgiEnv)
                  ->will($this->returnValue($fakeAsgiResponse));
        
        $mod = new ModWebsocket($server, $wsHandler);
        $mod->onHeaders($requestId);
    }
    
}

class StubWebsocketHandler extends Aerys\Handlers\Websocket\Handler {
    function __construct() {}
}

