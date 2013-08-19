<?php

use Alert\Reactor,
    Alert\NativeReactor,
    Artax\Request,
    Artax\AsyncClient,
    Aerys\Server,
    Aerys\Host;

class ExampleIteratorBody implements \Iterator {
    private $position = 0;
    private $parts = ['one', 'two', NULL, 'three'];

    function rewind() { $this->position = 0; }
    function current() { return $this->parts[$this->position]; }
    function key() { return $this->position; }
    function next() { $this->position++; }
    function valid() { return array_key_exists($this->position, $this->parts); }
}

class IntegrationServerApp {

    function __invoke(array $asgiEnv, $requestId) {
        switch ($asgiEnv['REQUEST_URI']) {
            case '/return_body':
                return $this->returnBody($asgiEnv, $requestId);
            case '/iterator_body':
                return $this->iteratorBody($asgiEnv, $requestId);
            default:
                return $this->hello($asgiEnv, $requestId);
        }
    }

    private function hello() {
        $body = '<html><body><h1>Hello, World.</h1></body></html>';
        return [200, 'OK', $headers = [], $body];
    }

    private function returnBody($asgiEnv) {
        $body = stream_get_contents($asgiEnv['ASGI_INPUT']);
        return [200, 'OK', $headers = [], $body];
    }

    private function iteratorBody() {
        return [200, 'OK', $headers = [], new ExampleIteratorBody];
    }

}

class ServerIntegrationTest extends PHPUnit_Framework_TestCase {

    private static $reactor;
    private static $server;
    private static $client;
    private $onResponse;

    static function setUpBeforeClass() {
        self::$reactor = new NativeReactor;
        self::$server = new Server(self::$reactor);
        self::$server->setOption('verbosity', 0);

        $host = new Host('127.0.0.1', 1500, '127.0.0.1', new IntegrationServerApp);
        self::$server->registerHost($host);
        self::$server->start();
        self::$client = new AsyncClient(self::$reactor);
    }

    function onArtaxClientError(\Exception $e) {
        self::$reactor->stop();
        $this->fail($e->getMessage());
    }

    function testBasicResponse() {
        $this->onResponse = function($response) {
            $this->assertEquals(200, $response->getStatus());
            $this->assertEquals('OK', $response->getReason());
            $this->assertEquals('<html><body><h1>Hello, World.</h1></body></html>', $response->getBody());
            $this->assertEquals(strlen($response->getBody()), current($response->getHeader('Content-Length')));
            $this->assertEquals('text/html; charset=utf-8', current($response->getHeader('Content-Type')));
            self::$reactor->stop();
        };

        self::$reactor->immediately(function() {
            $uri = 'http://127.0.0.1:1500/';
            self::$client->request($uri, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }

    function testRequestBody() {
        $requestBody = 'test';
        $this->onResponse = function($response) use ($requestBody) {
            $this->assertEquals(200, $response->getStatus());
            $this->assertEquals($requestBody, $response->getBody());
            self::$reactor->stop();
        };

        self::$reactor->immediately(function() use ($requestBody) {
            $uri = 'http://127.0.0.1:1500/return_body';
            $request = (new Request)->setUri($uri)->setMethod('POST')->setBody($requestBody);
            self::$client->request($request, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }

    function testDisallowedMethod() {
        $this->onResponse = function($response) {
            $this->assertEquals(405, $response->getStatus());
            self::$reactor->stop();
        };

        self::$reactor->immediately(function() {
            $uri = 'http://127.0.0.1:1500/';
            $request = (new Request)->setUri($uri)->setMethod('ZANZIBAR');
            self::$client->request($request, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }

    function testIteratorBody() {
        $this->onResponse = function($response) {
            $this->assertEquals(200, $response->getStatus());
            $this->assertEquals('onetwothree', $response->getBody());
            self::$reactor->stop();
        };

        self::$reactor->immediately(function() {
            $uri = 'http://127.0.0.1:1500/iterator_body';
            self::$client->request($uri, $this->onResponse, [$this, 'onArtaxClientError']);
        });

        self::$reactor->run();
    }

    function testHeadResponse() {
        $sock = stream_socket_client('tcp://127.0.0.1:1500');

        $request = "HEAD / HTTP/1.1\r\n";
        $request.= "Host: 127.0.0.1:1500\r\n";
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
        $sock = stream_socket_client('tcp://127.0.0.1:1500');

        $request = "TRACE / HTTP/1.0\r\n";
        $request.= "User-Agent: My-User-Agent\r\n";
        $request.= "Host: 127.0.0.1:1500\r\n";
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
        $sock = stream_socket_client('tcp://127.0.0.1:1500');

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

        $sock = stream_socket_client('tcp://127.0.0.1:1500');

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

        $sock = stream_socket_client('tcp://127.0.0.1:1500');

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

        $sock = stream_socket_client('tcp://127.0.0.1:1500');

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

        $sock = stream_socket_client('tcp://127.0.0.1:1500');

        $request = "POST /test HTTP/1.1\r\n";
        $request.= "Host: 127.0.0.1:1500\r\n";
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
        $sock = stream_socket_client('tcp://127.0.0.1:1500');

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

    function testStartStopPauseAndResume() {
        self::$server->pause();
        self::$server->resume();
        self::$server->stop();
        usleep(100000);
        self::$server->start();
    }

}
