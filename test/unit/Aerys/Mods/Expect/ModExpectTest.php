<?php

use Aerys\Mods\Expect\ModExpect,
    Aerys\Status,
    Aerys\Reason;

class ModExpectTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException UnexpectedValueException
     */
    function testConstructorThrowsOnEmptyConfigArray() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $mod = new ModExpect($server, []);
    }
    
    /**
     * @expectedException InvalidArgumentException
     */
    function testConstructorThrowsOnNonCallableValueInConfigArray() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $mod = new ModExpect($server, ['/uri', "something that isn't callable"]);
    }
    
    function testOnHeadersTakesNoActionIfExpectHeaderNotPresent() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        
        $requestId = 42;
        $asgiEnv = [
            'REQUEST_URI' => '/some_uri',
            'QUERY_STRING' => ''
        ];
        
        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));
        
        $server->expects($this->never())
               ->method('setResponse');
        
        $callbacks = [
            '/some_uri' => function() { return TRUE; }
        ];
        
        $mod = new ModExpect($server, $callbacks);
        $mod->onHeaders($requestId);
    }
    
    function testOnHeadersTakesNoActionIfExpectHeaderDoesntMatch100Continue() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        
        $requestId = 42;
        $asgiEnv = [
            'REQUEST_URI' => '/some_uri',
            'QUERY_STRING' => '',
            'HTTP_EXPECT' => 'ZANZIBAR!'
        ];
        
        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));
        
        $server->expects($this->never())
               ->method('setResponse');
        
        $callbacks = [
            '/some_uri' => function() { return TRUE; }
        ];
        
        $mod = new ModExpect($server, $callbacks);
        $mod->onHeaders($requestId);
    }
    
    function testOnHeadersSets100ContinueResponseIfNoCallbackRegisteredForThisUri() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        
        $requestId = 42;
        $asgiEnv = [
            'REQUEST_URI' => '/some_other_uri',
            'QUERY_STRING' => '',
            'HTTP_EXPECT' => '100-continue'
        ];
        
        $expectedAsgiResponse = [
            Status::CONTINUE_100,
            Reason::HTTP_100,
            [],
            NULL
        ];
        
        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));
        
        $server->expects($this->once())
               ->method('setResponse')
               ->with($requestId, $expectedAsgiResponse);
        
        $callbacks = [
            '/some_uri' => function() { return TRUE; }
        ];
        
        $mod = new ModExpect($server, $callbacks);
        $mod->onHeaders($requestId);
    }
    
    function testOnHeadersSets100ContinueResponseOnTruthyUserCallbackReturn() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        
        $requestId = 42;
        $asgiEnv = [
            'REQUEST_URI' => '/some_uri',
            'QUERY_STRING' => '',
            'HTTP_EXPECT' => '100-continue'
        ];
        
        $expectedAsgiResponse = [
            Status::CONTINUE_100,
            Reason::HTTP_100,
            [],
            NULL
        ];
        
        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));
        
        $server->expects($this->once())
               ->method('setResponse')
               ->with($requestId, $expectedAsgiResponse);
        
        $callbacks = [
            '/some_uri' => function() { return TRUE; }
        ];
        
        $mod = new ModExpect($server, $callbacks);
        $mod->onHeaders($requestId);
    }
    
    function testOnHeadersSets417ExpectationFailedResponseOnFalsyUserCallbackReturn() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        
        $requestId = 42;
        $asgiEnv = [
            'REQUEST_URI' => '/some_uri',
            'QUERY_STRING' => '',
            'HTTP_EXPECT' => '100-continue'
        ];
        
        $expectedAsgiResponse = [
            Status::EXPECTATION_FAILED,
            Reason::HTTP_417,
            [],
            NULL
        ];
        
        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));
        
        $server->expects($this->once())
               ->method('setResponse')
               ->with($requestId, $expectedAsgiResponse);
        
        $callbacks = [
            '/some_uri' => function() { return FALSE; }
        ];
        
        $mod = new ModExpect($server, $callbacks);
        $mod->onHeaders($requestId);
    }
    
    function testOnHeadersRemovesQueryStringForUriPathComparison() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'setResponse'], [$reactor]);
        
        $requestId = 42;
        $asgiEnv = [
            'REQUEST_URI' => '/some_uri?value=zanzibar',
            'QUERY_STRING' => '?value=zanzibar',
            'HTTP_EXPECT' => '100-continue'
        ];
        
        $expectedAsgiResponse = [
            Status::CONTINUE_100,
            Reason::HTTP_100,
            [],
            NULL
        ];
        
        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));
        
        $server->expects($this->once())
               ->method('setResponse')
               ->with($requestId, $expectedAsgiResponse);
        
        $callbacks = [
            '/some_uri' => function() { return TRUE; }
        ];
        
        $mod = new ModExpect($server, $callbacks);
        $mod->onHeaders($requestId);
    }
    
}

