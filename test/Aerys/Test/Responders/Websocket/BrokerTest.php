<?php

namespace Aerys\Test\Handlers\Broker;

use Aerys\Responders\Websocket\Broker,
    Aerys\Status,
    Aerys\Server;

class BrokerTest extends \PHPUnit_Framework_TestCase {
    
    function test101ReturnedOnSuccessfulHandshake() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '?var=should-be-replaced',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::SWITCHING_PROTOCOLS, $asgiResponse[0]);
    }
    
    function test404ReturnedIfNoEndpointMatchesRequestUri() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/this-doesnt-match-the-chat-endpoint',
            'QUERY_STRING' => ''
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::NOT_FOUND, $asgiResponse[0]);
        
    }
    
    function test405ReturnedIfNotHttpGetRequest() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => ''
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::METHOD_NOT_ALLOWED, $asgiResponse[0]);
    }
    
    function test505ReturnedIfBadHttpProtocol() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.0',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => ''
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::HTTP_VERSION_NOT_SUPPORTED, $asgiResponse[0]);
    }
    
    /**
     * @dataProvider provideInvalidUpgradeHeaders
     */
    function test426ReturnedOnInvalidUpgradeHeader($asgiEnv) {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::UPGRADE_REQUIRED, $asgiResponse[0]);
    }
    
    function provideInvalidUpgradeHeaders() {
        $return = [];
        
        // 0 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => ''
        ];
        $return[] = [$asgiEnv];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'should be websocket'
        ];
        $return[] = [$asgiEnv];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    /**
     * @dataProvider provideInvalidConnectionHeaders
     */
    function test400ReturnedOnInvalidConnectionHeader($asgiEnv) {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function provideInvalidConnectionHeaders() {
        $return = [];
        
        // 0 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => ''
        ];
        $return[] = [$asgiEnv];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'should be upgrade'
        ];
        $return[] = [$asgiEnv];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    function test400ReturnedOnMissingSecBrokerKeyHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function test400ReturnedOnEmptySecBrokerVersionHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16)
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function test400ReturnedOnUnmatchedSecBrokerVersionHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '10,11,12'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function test403ReturnedOnUnmatchedOriginHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        
        $endpoint = $this->getMock('Aerys\Responders\Websocket\Endpoint');
        $options = ['allowedOrigins' => ['site.com']];
        
        $handler->registerEndpoint('/chat', $endpoint, $options);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13',
            'HTTP_ORIGIN' => 'http://someothersite.com'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::FORBIDDEN, $asgiResponse[0]);
    }
    
    function test400ReturnedOnUnmatchedSecBrokerProtocolHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        
        $endpoint = $this->getMock('Aerys\Responders\Websocket\Endpoint');
        $options = ['subprotocol' => 'some-protocol'];
        
        $handler->registerEndpoint('/chat', $endpoint, $options);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13',
            'HTTP_SEC_WEBSOCKET_PROTOCOL' => 'some-other-protocol'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv, $requestId = 42);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
}

