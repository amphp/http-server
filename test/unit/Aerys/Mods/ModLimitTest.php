<?php

use Aerys\Mods\ModLimit,
    Aerys\Status,
    Aerys\Reason;

class ModLimitTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException InvalidArgumentException
     */
    function testConstructorThrowsOnEmptyLimitsArray() {
        $reactor = $this->getMock('Amp\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        
        $mod = new ModLimit($server, []);
    }
    
    function testOnRequest() {
        $reactor = $this->getMock('Amp\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        
        $requestId = 42;
        $asgiEnv = [
            'REMOTE_ADDR' => '123.456.789.1',
            'REQUEST_URI' => '/some_uri'
        ];
        
        $server->expects($this->exactly(3))
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));
        $server->expects($this->never())
               ->method('setResponse');
        
        $config = [
            'limits' => [
                60 => 100,
                3600 => 2500
            ]
        ];
        
        $mod = new ModLimit($server, $config);
        
        $mod->onRequest($requestId);
        $mod->onRequest($requestId);
        $mod->onRequest($requestId);
    }
    
    function testOnRequestRateLimitsExcessiveRequests() {
        $reactor = $this->getMock('Amp\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        
        $requestId = 42;
        $asgiEnv = [
            'X-FORWARDED-FOR' => '123.456.789.1',
            'REMOTE_ADDR' => 'some proxy addr',
            'REQUEST_URI' => '/some_uri'
        ];
        
        $server->expects($this->exactly(2))
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));
        $server->expects($this->exactly(2))
               ->method('setResponse');
        
        $config = [
            'ipProxyHeader' => 'X-FORWARDED-FOR',
            'limits' => [
                1 => 0
            ]
        ];
        
        $mod = new ModLimit($server, $config);
        
        $mod->onRequest($requestId);
        $mod->onRequest($requestId);
    }
    
}

