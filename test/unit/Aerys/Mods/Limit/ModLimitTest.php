<?php

use Aerys\Mods\Limit\ModLimit,
    Aerys\Status,
    Aerys\Reason;

class ModLimitTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException \InvalidArgumentException
     */
    function testConstructorThrowsOnEmptyLimitsArray() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        
        $mod = new ModLimit($server, []);
    }
    
    /**
     * @expectedException \InvalidArgumentException
     * @dataProvider provideInvalidConfigArrays
     */
    function testConstructorThrowsExceptionOnInvalidConfig($badArrayConfig) {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        $mod = new ModLimit($server, $badArrayConfig);
    }
    
    function provideInvalidConfigArrays() {
        $return  = [];
        
        // 0 -------------------------------------------------------------------------------------->
        
        $return[] = [['limits' => [
            0 => 100
        ]]];
        
        // 1 -------------------------------------------------------------------------------------->
        
        $return[] = [['limits' => [
            '-1' => 100
        ]]];
        
        // 2 -------------------------------------------------------------------------------------->
        
        $return[] = [['limits' => [
            42 => 0
        ]]];
        
        // 3 -------------------------------------------------------------------------------------->
        
        $return[] = [['limits' => [
            'test' => 50
        ]]];
        
        // 4 -------------------------------------------------------------------------------------->
        
        $return[] = [['limits' => [
            60 => -1
        ]]];
        
        // x -------------------------------------------------------------------------------------->
        
        return $return;
    }
    
    function testOnHeaders() {
        $reactor = $this->getMock('Alert\Reactor');
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
        
        $mod->onHeaders($requestId);
        $mod->onHeaders($requestId);
        $mod->onHeaders($requestId);
    }
    
    function testOnHeadersRateLimitsExcessiveRequests() {
        $reactor = $this->getMock('Alert\Reactor');
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
        $server->expects($this->exactly(1))
               ->method('setResponse');
        
        $config = [
            'ipProxyHeader' => 'X-FORWARDED-FOR',
            'limits' => [
                60 => 1
            ]
        ];
        
        $mod = new ModLimit($server, $config);
        
        $mod->onHeaders($requestId);
        $mod->onHeaders($requestId);
    }
    
}

