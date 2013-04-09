<?php

use Aerys\Handlers\Websocket\Handler,
    Aerys\Handlers\Websocket\SessionManager,
    Aerys\Status;

class WebsocketHandlerTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideInvalidEndpointArrays
     * @expectedException InvalidArgumentException
     */
    function testConstructorThrowsExceptionOnInvalidEndpointArray($endpoints) {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        $handler = new Handler($sessMgr, $endpoints);
    }
    
    function provideInvalidEndpointArrays() {
        $return = [];
        
        // 0 ---------------------------------------------------------------------------------------
        
        $endpoints = [];
        $return[] = [$endpoints];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $endpoints = [
            '/chat' => new StdClass
        ];
        
        $return[] = [$endpoints];
        
        // 2 ---------------------------------------------------------------------------------------
        
        $endpoints = [
            '/chat' => ['there should be an Endpoint instance here', $opts = []]
        ];
        
        $return[] = [$endpoints];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    function test101ReturnedOnSuccessfulHandshake() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '?var=should-be-replaced',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::SWITCHING_PROTOCOLS, $asgiResponse[0]);
    }
    
    function testBeforeHandshakeCallbackResponseReturned() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => [$this->getMock('Aerys\Handlers\Websocket\Endpoint'), [
                'beforeHandshake' => function() { return 42; }
            ]]
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '?var=should-be-replaced',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(42, $asgiResponse);
    }
    
    function testBeforeHandshakeCallbackExceptionReturns500Response() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $exception = new \Exception('DOH!');
        
        $endpoints = [
            '/chat' => [$this->getMock('Aerys\Handlers\Websocket\Endpoint'), [
                'beforeHandshake' => function() use ($exception) { throw $exception; }
            ]]
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $errorStream = fopen('php://memory', 'r+');
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '?var=should-be-replaced',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13',
            'ASGI_ERROR' => $errorStream
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::INTERNAL_SERVER_ERROR, $asgiResponse[0]);
        
        rewind($errorStream);
        $this->assertEquals((string) $exception, stream_get_contents($errorStream));
    }
    
    function test404ReturnedIfNoEndpointMatchesRequestUri() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/this-doesnt-match-the-chat-endpoint',
            'QUERY_STRING' => ''
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::NOT_FOUND, $asgiResponse[0]);
        
    }
    
    function test405ReturnedIfNotHttpGetRequest() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => ''
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::METHOD_NOT_ALLOWED, $asgiResponse[0]);
    }
    
    function test505ReturnedIfBadHttpProtocol() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.0',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => ''
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::HTTP_VERSION_NOT_SUPPORTED, $asgiResponse[0]);
    }
    
    /**
     * @dataProvider provideInvalidUpgradeHeaders
     */
    function test426ReturnedOnInvalidUpgradeHeader($asgiEnv) {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::UPGRADE_REQUIRED, $asgiResponse[0]);
    }
    
    function provideInvalidUpgradeHeaders() {
        $return = [];
        
        // 0 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => ''
        ];
        $return[] = [$asgiEnv];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
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
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function provideInvalidConnectionHeaders() {
        $return = [];
        
        // 0 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => ''
        ];
        $return[] = [$asgiEnv];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'should be upgrade'
        ];
        $return[] = [$asgiEnv];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    function test400ReturnedOnMissingSecWebsocketKeyHeader() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function test400ReturnedOnEmptySecWebsocketVersionHeader() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16)
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function test400ReturnedOnUnmatchedSecWebsocketVersionHeader() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '10,11,12'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function test403ReturnedOnUnmatchedOriginHeader() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => [$this->getMock('Aerys\Handlers\Websocket\Endpoint'), [
                'allowedOrigins' => ['http://mysite.com']
            ]]
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13',
            'HTTP_ORIGIN' => 'http://someothersite.com'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::FORBIDDEN, $asgiResponse[0]);
    }
    
    function test400ReturnedOnUnmatchedSecWebsocketProtocolHeader() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = new SessionManager($reactor);
        
        $endpoints = [
            '/chat' => [$this->getMock('Aerys\Handlers\Websocket\Endpoint'), [
                'subprotocol' => 'some-protocol'
            ]]
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13',
            'HTTP_SEC_WEBSOCKET_PROTOCOL' => 'some-other-protocol'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::BAD_REQUEST, $asgiResponse[0]);
    }
    
    function testImportSocketOpensNewClientSession() {
        $reactor = $this->getMock('Amp\Reactor');
        $sessMgr = $this->getMock('Aerys\Handlers\Websocket\SessionManager', ['open'], [$reactor]);
        
        $endpoints = [
            '/chat' => $this->getMock('Aerys\Handlers\Websocket\Endpoint')
        ];
        
        $handler = new Handler($sessMgr, $endpoints);
        $sessMgr->expects($this->once())
                ->method('open');
        
        $asgiEnv = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chat',
            'QUERY_STRING' => '?var=should-be-replaced',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13'
        ];
        
        $socket = 'testval';
        
        $handler->importSocket($socket, $asgiEnv);
    }
}

