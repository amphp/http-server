<?php

namespace Aerys\Test\Mods\Websocket;

use Aerys\Mods\Websocket\ModWebsocket;

class ModWebsocketTest extends \PHPUnit_Framework_TestCase {

    function testOnRequestDelegatesCallsToWebsocketHandler() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        $wsHandler = $this->getMock('Aerys\Handlers\Websocket\WebsocketHandler', ['__invoke'], [$reactor, $server]);

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
