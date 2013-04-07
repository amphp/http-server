<?php

use Aerys\Mods\ModSendFile,
    org\bovigo\vfs\vfsStream;

class ModSendFileTest extends PHPUnit_Framework_TestCase {
    
    private static $root;
    
    static function setUpBeforeClass() {
        self::$root = vfsStream::setup('root');
        $path = FIXTURE_DIR . '/vfs/mod_send_file_root';
        vfsStream::copyFromFileSystem($path, self::$root);
    }
    
    static function tearDownAfterClass() {
        self::$root = NULL;
    }
    
    function testBeforeResponseTakesNoActionIfMissingSendFileHeader() {
        $reactor = $this->getMock('Amp\Reactor');
        $server = $this->getMock('Aerys\Server', ['getResponse'], [$reactor]);
        $filesysHandler = $this->getMock('Aerys\Handlers\StaticFiles\Handler', NULL, ['vfs://root']);
        
        $requestId = 42;
        $asgiResponse = [
            $status = 200,
            $reason = 'OK',
            $headers = [],
            $body = NULL
        ];
        
        $server->expects($this->once())
               ->method('getResponse')
               ->with($requestId)
               ->will($this->returnValue($asgiResponse));
        
        $mod = new ModSendFile($server, $filesysHandler);
        $mod->beforeResponse($requestId);
    }
    
    function testBeforeResponseAssignsFileResourceOnSendFileHeaderMatch() {
        $reactor = $this->getMock('Amp\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'getResponse', 'setResponse'], [$reactor]);
        $filesysHandler = $this->getMock('Aerys\Handlers\StaticFiles\Handler', ['__invoke'], ['vfs://root']);
        
        $requestId = 42;
        $originalAsgiResponse = [
            $status = 200,
            $reason = 'OK',
            $headers = [
                'CONTENT-LENGTH' => 42,
                'X-SENDFILE' => '/test.txt',
                'SOME-OTHER-HEADER' => 'custom value'
            ],
            $body = NULL
        ];
        
        $fakeAsgiEnv = ['placeholder for a real ASGI environment array'];
        
        $filesysAsgiResponse = [
            $status = 200,
            $reason = 'OK',
            $headers = [
                'CONTENT-LENGTH' => 999,
                'CONTENT-TYPE' => 'text/plain'
            ],
            $body = 'test placeholder value'
        ];
        
        $mergedAsgiResponse = [
            $status = 200,
            $reason = 'OK',
            $headers = [
                'CONTENT-LENGTH' => 999,
                'CONTENT-TYPE' => 'text/plain',
                'SOME-OTHER-HEADER' => 'custom value'
            ],
            $body = 'test placeholder value'
        ];
        
        $server->expects($this->once())
               ->method('getResponse')
               ->with($requestId)
               ->will($this->returnValue($originalAsgiResponse));
        
        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($fakeAsgiEnv));
        
        $filesysHandler->expects($this->once())
                       ->method('__invoke')
                       ->with($fakeAsgiEnv)
                       ->will($this->returnValue($filesysAsgiResponse));
        
        $server->expects($this->once())
               ->method('setResponse')
               ->with($requestId, $mergedAsgiResponse);
        
        $mod = new ModSendFile($server, $filesysHandler);
        $mod->beforeResponse($requestId);
    }
    
}

