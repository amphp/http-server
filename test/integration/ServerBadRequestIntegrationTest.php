<?php

/**
 * These tests will not add any test coverage because the Aerys server executes in a separate
 * process. Instead, this code tests integration of the server as a whole by making requests over
 * the TCP stack to the server process and lets us know if the response we received differs from
 * what was expected.
 * 
 * Note also that the server process's STDERR is piped to the test process's STDERR stream. This
 * allows us to see exactly what went wrong in the event of runtime errors triggered by the server.
 */
class ServerBadRequestIntegrationTest extends PHPUnit_Framework_TestCase {
    
    private static $serverProcess;
    private static $serverPipes;
    
    private $isServerDead = FALSE;
    
    static function setUpBeforeClass() {
        $serverPath = FIXTURE_DIR . '/bad_request_integration_server.php';
        $cmd = self::generatePhpBinaryCmd($serverPath);
        $descriptors = [
           2 => ["pipe", STDERR, "w"] // pipe STDERR to this process's STDERR
        ];
        
        self::$serverProcess = proc_open($cmd, $descriptors, self::$serverPipes);
        
        // If we don't give the server process a moment to boot up and bind its socket we risk
        // getting a socket exception when we try to connect in our tests.
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
    
    function testServerSends431IfHeadersTooLarge() {
        $this->skipIfServerHasDied();
        
        $rawRequest = "GET / HTTP/1.0\r\n" .
            "X-My-Too-Long-Header: " . str_repeat('x', 512) . "\r\n\r\n";
        $sock = stream_socket_client('tcp://127.0.0.1:1500', $errNo, $errStr, $timeout=42, STREAM_CLIENT_CONNECT);
        
        fwrite($sock, $rawRequest);
        
        $statusLine = rtrim(fgets($sock), "\r\n");
        $this->assertEquals('HTTP/1.0 431 Request Header Fields Too Large', $statusLine);
    }
    
    function testServerSends413IfEntityBodyTooLarge() {
        $this->skipIfServerHasDied();
        
        $rawRequest = "POST / HTTP/1.0\r\n" .
            "Content-Length: 5\r\n\r\n" .
            "woot!";
        $sock = stream_socket_client('tcp://127.0.0.1:1500', $errNo, $errStr, $timeout=42, STREAM_CLIENT_CONNECT);
        
        fwrite($sock, $rawRequest);
        
        $statusLine = rtrim(fgets($sock), "\r\n");
        $this->assertEquals('HTTP/1.0 413 Request Entity Too Large', $statusLine);
    }
    
    function testServerSends400ResponseOnGarbageRequest() {
        $this->skipIfServerHasDied();
        
        $rawRequest = "some nonsense\r\nfas;fjdlfjadf;\najdlfaj\r\n\r\n";
        $sock = stream_socket_client('tcp://127.0.0.1:1500', $errNo, $errStr, $timeout=42, STREAM_CLIENT_CONNECT);
        
        fwrite($sock, $rawRequest);
        
        $statusLine = rtrim(fgets($sock), "\r\n");
        $this->assertEquals('HTTP/1.0 400 Bad Request', $statusLine);
    }
    
    function testServerSends400ResponseOnGarbageRequestWithoutDoubleLineFeed() {
        $this->skipIfServerHasDied();
        
        /**
         * @TODO Add StartLine message parsing
         */
        $this->fail(
            'This definitely fails right now'
        );
        
        $rawRequest = "some nonsense \r\nin which the headers never finish";
        $sock = stream_socket_client('tcp://127.0.0.1:1500', $errNo, $errStr, $timeout=42, STREAM_CLIENT_CONNECT);
        
        fwrite($sock, $rawRequest);
        
        $statusLine = rtrim(fgets($sock), "\r\n");
        $this->assertEquals('HTTP/1.0 400 Bad Request', $statusLine);
    }
}

