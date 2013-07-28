<?php

use Aerys\Mods\ErrorPages\ModErrorPages,
    org\bovigo\vfs\vfsStream;

class ModErrorPagesTest extends PHPUnit_Framework_TestCase {
    
    private static $root;
    
    static function setUpBeforeClass() {
        self::$root = vfsStream::setup('root');
        $path = FIXTURE_DIR . '/vfs/mod_error_pages';
        vfsStream::copyFromFileSystem($path, self::$root);
    }
    
    static function tearDownAfterClass() {
        self::$root = NULL;
    }
    
    /**
     * @dataProvider provideInvalidFileNames
     * @expectedException RuntimeException
     */
    function testConstructorThrowsExceptionOnUnreadableFile($filePath) {
        $reactor = $this->getMock('Amp\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $options = [404 => [$filePath, 'text/html']];
        $mod = new ModErrorPages($server, $options);
    }
    
    function provideInvalidFileNames() {
        return [
            ['vfs://root/empty_dir'],
            ['vfs://file-that-doesnt-exist']
        ];
    }
    
    function testBeforeResponse() {
        $reactor = $this->getMock('Amp\Reactor');
        $server = $this->getMock('Aerys\Server', ['getResponse', 'setResponse'], [$reactor]);
        
        $replacementFilePath = 'vfs://root/404.html';
        $options = [404 => [$replacementFilePath, 'text/html']];
        $expectedBody = file_get_contents($replacementFilePath);
        $contentLength = strlen($expectedBody);
        $contentType = $options[404][1];
        
        $requestId = 42;
        
        $originalResponse = [
            $status = 404,
            $reason = 'Not Found',
            $headers = [],
            $body = "That resource doesn't exist!"
        ];
        
        $expectedResult = [
            $status = 404,
            $reason = 'Not Found',
            $headers = "Content-Type: {$contentType}",
            $expectedBody
        ];
        
        $server->expects($this->once())
               ->method('getResponse')
               ->with($requestId)
               ->will($this->returnValue($originalResponse));
        
        $server->expects($this->once())
               ->method('setResponse')
               ->with($requestId, $expectedResult);
        
        $mod = new ModErrorPages($server, $options);
        $mod->beforeResponse($requestId);
    }
    
}

