<?php

use Aerys\Writing\StreamWriter,
    org\bovigo\vfs\vfsStream;

class StreamWriterTest extends PHPUnit_Framework_TestCase {
    
    private static $root;
    
    static function setUpBeforeClass() {
        self::$root = vfsStream::setup('root');
        $path = FIXTURE_DIR . '/vfs/misc';
        vfsStream::copyFromFileSystem($path, self::$root);
    }
    
    static function tearDownAfterClass() {
        self::$root = NULL;
    }
    
    function testWrite() {
        $destination = fopen('php://memory', 'r+');
        $source = fopen('vfs://root/resource_body_writer_test.txt', 'r');
        
        $headers = 'test headers';
        $expectedBody = $headers . file_get_contents('vfs://root/resource_body_writer_test.txt');
        
        $writer = new StreamWriter($destination, $headers, $source);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($expectedBody, stream_get_contents($destination));
    }
    
}

