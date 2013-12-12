<?php

namespace Aerys\Test;

use Alert\Reactor,
    Alert\NativeReactor,
    Artax\Request,
    Artax\AsyncClient,
    Aerys\Server,
    Aerys\Host;

class Host1App {

    function __invoke($request) {
        switch ($request['REQUEST_URI']) {
            case '/return_body':
                return $this->returnBody($request);
            default:
                return $this->hello($request);
        }
    }

    private function hello() {
        return '<html><body><h1>Hello, World.</h1></body></html>';
    }

    private function returnBody($request) {
        return stream_get_contents($request['ASGI_INPUT']);
    }

}

class Host2App {
    function __invoke($request) {
        return $this->hello($request);
    }

    private function hello() {
        return '<html><body><h1>Hello, World.</h1></body></html>';
    }
}

class ServerIntegrationTest extends \PHPUnit_Framework_TestCase {

    private static $reactor;
    private static $server;
    private static $client;
    private $onResponse;

    static function setUpBeforeClass() {
        self::$reactor = new NativeReactor;
        self::$server = new Server(self::$reactor);

        $host1 = new Host('127.0.0.1', 1501, '', new Host1App);
        $host2 = new Host('127.0.0.1', 1501, 'somesite.com', new Host2App);

        self::$server->start([$host1, $host2]);
        self::$client = new AsyncClient(self::$reactor);
    }

    function onArtaxClientError(\Exception $e) {
        self::$reactor->stop();
        $this->fail($e->getMessage());
    }

    function testBasicResponse() {
        $this->onResponse = function($response) {
            self::$reactor->stop();
            $this->assertEquals(200, $response->getStatus());
            $this->assertEquals('OK', $response->getReason());
            $this->assertEquals('<html><body><h1>Hello, World.</h1></body></html>', $response->getBody());
            $this->assertEquals(strlen($response->getBody()), current($response->getHeader('Content-Length')));
            $this->assertEquals('text/html; charset=utf-8', current($response->getHeader('Content-Type')));
        };

        self::$reactor->immediately(function() {
            $uri = 'http://127.0.0.1:1501/';
            self::$client->request($uri, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }

    function testDisallowedMethod() {
        $this->onResponse = function($response) {
            $this->assertEquals(405, $response->getStatus());
            self::$reactor->stop();
        };

        self::$reactor->immediately(function() {
            $uri = 'http://127.0.0.1:1501/';
            $request = (new Request)->setUri($uri)->setMethod('ZANZIBAR');
            self::$client->request($request, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }

    function testHeadResponse() {
        $sock = stream_socket_client('tcp://127.0.0.1:1501');

        $request = "HEAD / HTTP/1.1\r\n";
        $request.= "Host: 127.0.0.1:1501\r\n";
        $request.= "\r\n";

        self::$reactor->immediately(function() use ($sock, $request) {
            fwrite($sock, $request);
        });

        $watcher = self::$reactor->onReadable($sock, function() use ($sock, $request) {
            $data = fgets($sock);
            $this->assertEquals("HTTP/1.1 200 OK\r\n", $data);

            self::$reactor->stop();
        });

        self::$reactor->run();
        self::$reactor->cancel($watcher);
        @fclose($sock);
    }

    function testTraceResponse() {
        $sock = stream_socket_client('tcp://127.0.0.1:1501');

        $request = "TRACE / HTTP/1.0\r\n";
        $request.= "User-Agent: My-User-Agent\r\n";
        $request.= "Host: 127.0.0.1:1501\r\n";
        $request.= "\r\n";

        self::$reactor->immediately(function() use ($sock, $request) {
            fwrite($sock, $request);
        });

        $watcher = self::$reactor->onReadable($sock, function() use ($sock, $request) {
            $responseLines = [];
            while ($data = fgets($sock)) {
                $responseLines[] = $data;
            }

            $startLine = current($responseLines);
            $response = implode($responseLines);
            $body = substr($response, strpos($response, "\r\n\r\n") + 4);

            $this->assertEquals("HTTP/1.0 200 OK\r\n", $startLine);
            $this->assertEquals($body, rtrim($request, "\r\n") . "\r\n");

            self::$reactor->stop();
        });

        self::$reactor->run();
        self::$reactor->cancel($watcher);
        @fclose($sock);
    }

    function testBadRequest() {
        $sock = stream_socket_client('tcp://127.0.0.1:1501');

        $request = "some nonsense\r\nfas;fjdlfjadf;\najdlfaj\r\n\r\n";

        self::$reactor->immediately(function() use ($sock, $request) {
            fwrite($sock, $request);
        });

        $watcher = self::$reactor->onReadable($sock, function() use ($sock) {
            $statusLine = rtrim(fgets($sock), "\r\n");
            $this->assertEquals('HTTP/1.0 400 Bad Request', $statusLine);
            self::$reactor->stop();
        });

        self::$reactor->run();
        self::$reactor->cancel($watcher);
        @fclose($sock);
    }

    function test431ResponseWhenHeadersTooLarge() {
        $originalMaxHeaderBytes = self::$server->getOption('maxHeaderBytes');
        self::$server->setOption('maxHeaderBytes', 256);

        $sock = stream_socket_client('tcp://127.0.0.1:1501');

        $request = "GET / HTTP/1.0\r\n";
        $request.= "X-My-Too-Long-Header: " . str_repeat('x', 512) . "\r\n";
        $request.= "\r\n";

        self::$reactor->immediately(function() use ($sock, $request) {
            fwrite($sock, $request);
        });

        $watcher = self::$reactor->onReadable($sock, function() use ($sock) {
            $statusLine = rtrim(fgets($sock), "\r\n");
            $this->assertEquals('HTTP/1.0 431 Request Header Fields Too Large', $statusLine);
            self::$reactor->stop();
        });

        self::$reactor->run();
        self::$reactor->cancel($watcher);
        @fclose($sock);
        self::$server->setOption('maxHeaderBytes', $originalMaxHeaderBytes);
    }

    function test413ResponseWhenEntityBodyTooLarge() {
        $originalMaxBodyBytes = self::$server->getOption('maxBodyBytes');
        self::$server->setOption('maxBodyBytes', 3);

        $sock = stream_socket_client('tcp://127.0.0.1:1501');

        $request = "POST / HTTP/1.0\r\n";
        $request.= "Content-Length: 5\r\n";
        $request.= "\r\n";
        $request.= "woot!";

        self::$reactor->immediately(function() use ($sock, $request) {
            fwrite($sock, $request);
        });

        $watcher = self::$reactor->onReadable($sock, function() use ($sock) {
            $statusLine = rtrim(fgets($sock), "\r\n");
            $this->assertEquals('HTTP/1.0 413 Request Entity Too Large', $statusLine);
            self::$reactor->stop();
        });

        self::$reactor->run();
        self::$reactor->cancel($watcher);
        @fclose($sock);
        self::$server->setOption('maxBodyBytes', $originalMaxBodyBytes);
    }

    function testNewClientAcceptancePausedWhenMaxClientsReached() {
        $originalMaxConnections = self::$server->getOption('maxConnections');
        self::$server->setOption('maxConnections', 1);

        $sock = stream_socket_client('tcp://127.0.0.1:1501');

        $request = "GET / HTTP/1.0\r\n\r\n";

        self::$reactor->immediately(function() use ($sock, $request) {
            fwrite($sock, $request);
        });

        $watcher = self::$reactor->onReadable($sock, function() use ($sock) {
            $statusLine = rtrim(fgets($sock), "\r\n");
            $this->assertEquals('HTTP/1.0 200 OK', $statusLine);
            self::$reactor->stop();
        });

        self::$reactor->run();
        self::$reactor->cancel($watcher);
        @fclose($sock);
        self::$server->setOption('maxConnections', $originalMaxConnections);
    }

    function testLengthRequiredResponse() {
        $originalBodyLengthSetting = self::$server->getOption('requireBodyLength');
        self::$server->setOption('requireBodyLength', TRUE);

        $sock = stream_socket_client('tcp://127.0.0.1:1501');

        $request = "POST /test HTTP/1.1\r\n";
        $request.= "Host: 127.0.0.1:1501\r\n";
        $request.= "Transfer-Encoding: chunked\r\n";
        $request.= "Content-Type: text/plain\r\n";
        $request.= "\r\n";
        $request.= "4\r\ntest\r\n0\r\n\r\n";

        self::$reactor->immediately(function() use ($sock, $request) {
            fwrite($sock, $request);
        });

        $watcher = self::$reactor->onReadable($sock, function() use ($sock) {
            $statusLine = rtrim(fgets($sock), "\r\n");
            $this->assertEquals('HTTP/1.1 411 Length Required', $statusLine);
            self::$reactor->stop();
        });

        self::$reactor->run();
        self::$reactor->cancel($watcher);
        @fclose($sock);
        self::$server->setOption('requireBodyLength', $originalBodyLengthSetting);
    }

    function testBadHostResponse() {
        $sock = stream_socket_client('tcp://127.0.0.1:1501');

        $request = "GET / HTTP/1.1\r\n";
        $request.= "Host: zanzibar.com\r\n";
        $request.= "\r\n";

        self::$reactor->immediately(function() use ($sock, $request) {
            fwrite($sock, $request);
        });

        $watcher = self::$reactor->onReadable($sock, function() use ($sock) {
            $statusLine = rtrim(fgets($sock), "\r\n");
            $this->assertEquals('HTTP/1.1 400 Bad Request: Invalid Host', $statusLine);
            self::$reactor->stop();
        });

        self::$reactor->run();
        self::$reactor->cancel($watcher);
        @fclose($sock);
    }

    function testRequestBody() {
        $requestBody = 'test';
        $this->onResponse = function($response) use ($requestBody) {
            $this->assertEquals(200, $response->getStatus());
            $this->assertEquals($requestBody, $response->getBody());
            self::$reactor->stop();
        };

        self::$reactor->immediately(function() use ($requestBody) {
            $uri = 'http://127.0.0.1:1501/return_body';
            $request = (new Request)->setUri($uri)->setMethod('POST')->setBody($requestBody);
            self::$client->request($request, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }

}
