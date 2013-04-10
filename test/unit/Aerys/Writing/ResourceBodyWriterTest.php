<?php

use Aerys\Writing\ResourceBodyWriter,
    org\bovigo\vfs\vfsStream;

class ResourceBodyWriterTest extends PHPUnit_Framework_TestCase {
    
    private static $root;
    
    static function setUpBeforeClass() {
        self::$root = vfsStream::setup('root');
        $path = FIXTURE_DIR . '/vfs/misc';
        vfsStream::copyFromFileSystem($path, self::$root);
    }
    
    static function tearDownAfterClass() {
        self::$root = NULL;
    }
    
    /**
     * @dataProvider provideFailingResources
     * @expectedException Aerys\Writing\ResourceWriteException
     */
    function testWriteThrowsExceptionOnResourceWriteFailure($destination, $source) {
        $destination = 'will fail because this should be a resource';
        $source = fopen('php://memory', 'r+');
        $writer = new ResourceBodyWriter($destination, $source, 42);
        $writer->write();
    }
    
    function provideFailingResources() {
        return [
            ['will fail because this should be a resource', fopen('php://memory', 'r+')],
            [fopen('php://memory', 'r+'), 'will fail because this should be a resource']
        ];
    }
    
    /**
     * @TODO FIX ME! This is failing inexplicably :(
     */
    function testWrite() {
        $this->markTestSkipped();
        
        $destination = fopen('php://memory', 'r+');
        $source = fopen('vfs://root/resource_body_writer_test.txt', 'r');
        $expectedBody = file_get_contents('vfs://root/resource_body_writer_test.txt');
        
        $contentLength = strlen($expectedBody);
        $writer = new ResourceBodyWriter($destination, $source, $contentLength);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($expectedBody, stream_get_contents($destination));
    }
    
}

