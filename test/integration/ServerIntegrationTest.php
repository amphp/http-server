<?php

use Artax\Client,
    Artax\Request;

/**
 * These tests will not add any test coverage because the Aerys server executes in a separate
 * process. Instead, this code tests integration of the server as a whole by making requests over
 * the TCP stack to the server process and lets us know if the response we received differs from
 * what was expected.
 * 
 * Note also that the server process's STDERR is piped to the test process's STDERR stream. This
 * allows us to see exactly what went wrong in the event of runtime errors triggered by the server.
 */
class ServerIntegrationTest extends PHPUnit_Framework_TestCase {
    
    private static $serverProcess;
    private static $serverPipes;
    
    private $isServerDead = FALSE;
    
    static function setUpBeforeClass() {
        $serverPath = FIXTURE_DIR . '/integration_server.php';
        $cmd = self::generatePhpBinaryCmd($serverPath);
        $descriptors = [
           2 => ["pipe", STDERR, "w"] // pipe STDERR to this process's STDERR
        ];
        
        self::$serverProcess = proc_open($cmd, $descriptors, self::$serverPipes);
        
        // If we don't give the server process time to boot up and bind its socket we risk getting
        // a socket exception when we try to connect in our tests.
        usleep(100000);
    }
    
    private static function generatePhpBinaryCmd($serverPath) {
        $cmd = [];
        $cmd[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $cmd[] = "-c $ini";
        }
        $cmd[] = $serverPath;
        
        return implode(' ', $cmd);
    }
    
    static function tearDownAfterClass() {
        foreach (self::$serverPipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_terminate(self::$serverProcess);
    }
    
    function skipIfServerHasDied() {
        if ($this->isServerDead || !proc_get_status(self::$serverProcess)['running']) {
            $this->isServerDead = TRUE;
            $this->markTestSkipped(
                'Test skipped because the server process died'
            );
        }
    }
    
    // ---------------------------------- START TESTS --------------------------------------------->
    
    function testServerAddsMissingHeaders() {
        $this->skipIfServerHasDied();
        
        $client = new Client;
        $uri = 'http://' . INTEGRATION_SERVER_ADDR . '/adds_missing_headers';
        $response = $client->request($uri);
        
        $expectedBody = '<html><body><h1>Hello, World.</h1></body></html>';
        
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('OK', $response->getReason());
        $this->assertEquals($expectedBody, $response->getBody());
        
        $this->assertEquals(strlen($expectedBody), current($response->getHeader('Content-Length')));
        $this->assertEquals('text/html; charset=utf-8', current($response->getHeader('Content-Type')));
        $this->assertTrue($response->hasHeader('Date'));
    }
    
    function testServerAddsMissingContentTypeCharset() {
        $this->skipIfServerHasDied();
        
        $client = new Client;
        $uri = 'http://' . INTEGRATION_SERVER_ADDR . '/adds_missing_content_type_charset';
        $response = $client->request($uri);
        
        $expectedBody = 'Hello, World.';
        
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('OK', $response->getReason());
        $this->assertEquals($expectedBody, $response->getBody());
        $this->assertEquals(strlen($expectedBody), current($response->getHeader('Content-Length')));
        $this->assertEquals('text/plain; charset=utf-8', current($response->getHeader('Content-Type')));
        $this->assertTrue($response->hasHeader('Date'));
    }
    
    function testTraceResponse() {
        $this->skipIfServerHasDied();
        
        $client = new Client;
        $uri = 'http://' . INTEGRATION_SERVER_ADDR . '/trace_response';
        $request = (new Request)->setUri($uri)->setMethod('TRACE')->setAllHeaders([
            'User-Agent' => 'My-User-Agent',
            'Content-Length' => 4,
            'Content-Type' => 'text/plain; charset=utf-8'
        ])->setBody('test');
        
        $response = $client->request($request);
        
        $expectedResponseBody = '' .
            "TRACE /trace_response HTTP/1.1\r\n" .
            "User-Agent: My-User-Agent\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n" .
            "Host: " . INTEGRATION_SERVER_ADDR . "\r\n" .
            "Accept-Encoding: gzip, identity\r\n";
        
        $this->assertEquals($expectedResponseBody, $response->getBody());
    }
    
    function testMethodNotAllowedResponse() {
        $this->skipIfServerHasDied();
        
        $client = new Client;
        $uri = 'http://' . INTEGRATION_SERVER_ADDR . '/';
        $request = (new Request)->setUri($uri)->setMethod('ZANZIBAR');
        $response = $client->request($request);
        
        $this->assertEquals(405, $response->getStatus());
    }
    
    function testBadRequestResponseOnInvalidHttp11Host() {
        $this->skipIfServerHasDied();
        
        $client = new Client;
        $uri = 'http://' . INTEGRATION_SERVER_ADDR . '/';
        $request = (new Request)->setUri($uri)->setMethod('GET')->setProtocol('1.1')->setHeader('Host', 'badhost');
        $response = $client->request($request);
        
        $this->assertEquals(400, $response->getStatus());
    }
    
}










































