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

        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '?var=should-be-replaced',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '13'
        ];

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::SWITCHING_PROTOCOLS, $response['status']);
    }

    function test404ReturnedIfNoEndpointMatchesRequestUri() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));

        $request = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/this-doesnt-match-the-chat-endpoint',
            'QUERY_STRING' => ''
        ];

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::NOT_FOUND, $response['status']);

    }

    function test405ReturnedIfNotHttpGetRequest() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));

        $request = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => ''
        ];

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::METHOD_NOT_ALLOWED, $response['status']);
    }

    function test505ReturnedIfBadHttpProtocol() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));

        $request = [
            'SERVER_PROTOCOL' => '1.0',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => ''
        ];

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::HTTP_VERSION_NOT_SUPPORTED, $response['status']);
    }

    /**
     * @dataProvider provideInvalidUpgradeHeaders
     */
    function test426ReturnedOnInvalidUpgradeHeader($request) {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::UPGRADE_REQUIRED, $response['status']);
    }

    function provideInvalidUpgradeHeaders() {
        $return = [];

        // 0 ---------------------------------------------------------------------------------------

        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => ''
        ];
        $return[] = [$request];

        // 1 ---------------------------------------------------------------------------------------

        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'should be websocket'
        ];
        $return[] = [$request];

        // x ---------------------------------------------------------------------------------------

        return $return;
    }

    /**
     * @dataProvider provideInvalidConnectionHeaders
     */
    function test400ReturnedOnInvalidConnectionHeader($request) {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::BAD_REQUEST, $response['status']);
    }

    function provideInvalidConnectionHeaders() {
        $return = [];

        // 0 ---------------------------------------------------------------------------------------

        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => ''
        ];
        $return[] = [$request];

        // 1 ---------------------------------------------------------------------------------------

        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'should be upgrade'
        ];
        $return[] = [$request];

        // x ---------------------------------------------------------------------------------------

        return $return;
    }

    function test400ReturnedOnMissingSecBrokerKeyHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));

        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade'
        ];

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::BAD_REQUEST, $response['status']);
    }

    function test400ReturnedOnEmptySecBrokerVersionHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));

        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16)
        ];

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::BAD_REQUEST, $response['status']);
    }

    function test400ReturnedOnUnmatchedSecBrokerVersionHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);
        $handler->registerEndpoint('/chat', $this->getMock('Aerys\Responders\Websocket\Endpoint'));

        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/chat',
            'QUERY_STRING' => '',
            'HTTP_UPGRADE' => 'websocket',
            'HTTP_CONNECTION' => 'Upgrade',
            'HTTP_SEC_WEBSOCKET_KEY' => str_repeat('x', 16),
            'HTTP_SEC_WEBSOCKET_VERSION' => '10,11,12'
        ];

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::BAD_REQUEST, $response['status']);
    }

    function test403ReturnedOnUnmatchedOriginHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);

        $endpoint = $this->getMock('Aerys\Responders\Websocket\Endpoint');
        $options = ['allowedOrigins' => ['site.com']];

        $handler->registerEndpoint('/chat', $endpoint, $options);

        $request = [
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

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::FORBIDDEN, $response['status']);
    }

    function test400ReturnedOnUnmatchedSecBrokerProtocolHeader() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $handler = new Broker($reactor, $server);

        $endpoint = $this->getMock('Aerys\Responders\Websocket\Endpoint');
        $options = ['subprotocol' => 'some-protocol'];

        $handler->registerEndpoint('/chat', $endpoint, $options);

        $request = [
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

        $response = $handler->__invoke($request);

        $this->assertEquals(Status::BAD_REQUEST, $response['status']);
    }
}

