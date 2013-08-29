<?php

namespace Aerys\Test\Handlers\ReverseProxy;

use Alert\Reactor,
    Alert\NativeReactor,
    Artax\Request,
    Artax\AsyncClient,
    Aerys\Server,
    Aerys\Host,
    Aerys\Responders\ReverseProxy\ReverseProxyResponder;

class ProxyBackendIntegrationApp {

    private $server;

    function __construct($server) {
        $this->server = $server;
    }

    function __invoke(array $asgiEnv, $requestId) {
        switch ($asgiEnv['REQUEST_URI']) {
            case '/return_body':
                return $this->returnBody($asgiEnv, $requestId);
            default:
                return $this->hello($asgiEnv, $requestId);
        }
    }

    private function hello() {
        $body = '<html><body><h1>Hello, World.</h1></body></html>';
        return [200, 'OK', $headers = [], $body];
    }

    private function returnBody($asgiEnv, $requestId) {
        $body = stream_get_contents($asgiEnv['ASGI_INPUT']);
        return [200, 'OK', $headers = [], $body];
    }

}

class ReverseProxyIntegrationTest extends \PHPUnit_Framework_TestCase {

    private static $reactor;
    private static $server;
    private static $client;
    private static $proxy;
    private $onResponse;

    static function setUpBeforeClass() {
        self::$reactor = new NativeReactor;
        self::$server = new Server(self::$reactor);

        // Frontend proxy responder
        self::$proxy = new ReverseProxyResponder(self::$reactor, self::$server);
        self::$proxy->setOption('lowaterconnectionmin', 0);
        self::$proxy->setOption('proxyPassHeaders', [
            'Host'            => '$host',
            'X-Forwarded-For' => '$remoteAddr',
            'X-Real-Ip'       => '$serverAddr'
        ]);

        // Frontend
        $host = new Host('127.0.0.1', 1508, '127.0.0.1', self::$proxy);
        self::$server->addHost($host);

        // Backend
        $host = new Host('127.0.0.1', 1509, '127.0.0.1', new ProxyBackendIntegrationApp(self::$server));
        self::$server->addHost($host);

        // Async HTTP Client
        self::$client = new AsyncClient(self::$reactor);

        self::$server->listen();
    }

    function onArtaxClientError(\Exception $e) {
        self::$reactor->stop();
        $this->fail($e);
    }

    function testBasicResponse() {
        self::$proxy->addBackend('127.0.0.1:1509');
        self::$reactor->tick();

        $this->onResponse = function($response) {
            $this->assertEquals(200, $response->getStatus());
            $this->assertEquals('OK', $response->getReason());
            $this->assertEquals('<html><body><h1>Hello, World.</h1></body></html>', $response->getBody());
            $this->assertEquals(strlen($response->getBody()), current($response->getHeader('Content-Length')));
            $this->assertEquals('text/html; charset=utf-8', current($response->getHeader('Content-Type')));
            self::$reactor->stop();
        };

        self::$reactor->immediately(function() {
            $uri = 'http://127.0.0.1:1508/';
            self::$client->request($uri, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }

    function testReturnBody() {
        self::$proxy->addBackend('127.0.0.1:1509');
        self::$reactor->tick();

        $this->onResponse = function($response) {
            $this->assertEquals(200, $response->getStatus());
            $this->assertEquals('test', $response->getBody());
            self::$reactor->stop();
        };

        self::$reactor->immediately(function() {
            $uri = 'http://127.0.0.1:1508/return_body';
            $request = (new Request)->setUri($uri)->setMethod('POST')->setBody('test');
            self::$client->request($request, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }
}
