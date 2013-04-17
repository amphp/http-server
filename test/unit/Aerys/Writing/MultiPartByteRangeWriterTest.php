<?php

use Aerys\Writing\MultiPartByteRangeWriter,
    Aerys\Writing\MultiPartByteRangeBody;

class MultiPartByteRangeWriterTest extends PHPUnit_Framework_TestCase {
    
    function testWrite() {
        $resourceData = 'test line three';
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, $resourceData);
        rewind($resource);
        
        $ranges = [
            [0, 3],
            [5, 8],
            [10, 14]
        ];
        $boundary = 'abcdefghijklm';
        $contentType = 'text/plain';
        $contentLength = strlen($resourceData);
        
        $body = new MultiPartByteRangeBody($resource, $ranges, $boundary, $contentType, $contentLength);
        
        $headers = "headers\r\n";
        $expectedWrite = $headers;
        
        foreach ($ranges as $range) {
            list($startPos, $endPos) = $range;
        
            $expectedWrite .= '' .
                '--' . $body->getBoundary() . "\r\n" .
                'Content-Type: ' . $body->getContentType() . "\r\n" .
                "Content-Range: bytes {$startPos}-{$endPos}/" . $body->getContentLength() .
                "\r\n\r\n";
            
            $dataPart = substr($resourceData, $startPos, $endPos - $startPos + 1);
            $expectedWrite .= $dataPart;
        }
        
        $expectedWrite .= "--" . $body->getBoundary() . "--\r\n";
        
        $destination = fopen('php://memory', 'r+');
        
        $writer = new MultiPartByteRangeWriter($destination, $headers, $body);
        $writer->setGranularity(1);
        
        while(!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($expectedWrite, stream_get_contents($destination));
    }
    
    /**
     * @expectedException Aerys\Writing\ResourceWriteException
     */
    function testWriteThrowsExceptionOnResourceFailure() {
        $resource = fopen('php://memory', 'r+');
        $ranges = [
            [0, 42],
            [50, 99]
        ];
        $boundary = 'fsjahfjlskdafksd';
        $contentType = 'text/plain';
        $contentLength = 100;
        
        $body = new MultiPartByteRangeBody($resource, $ranges, $boundary, $contentType, $contentLength);
        
        $headers = "headers\r\n";
        $destination = 'should fail because this is not a resource';
        $writer = new MultiPartByteRangeWriter($destination, $headers, $body);
        $writer->write();
    }
    
    /**
     * @expectedException Aerys\Writing\ResourceWriteException
     */
    function testWriteThrowsExceptionOnMidWriteResourceFailure() {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, str_repeat('x', 1000));
        rewind($resource);
        
        $ranges = [
            [0, 499],
            [500, 999]
        ];
        $boundary = 'fsjahfjlskdafksd';
        $contentType = 'text/plain';
        $contentLength = 100;
        
        $body = new MultiPartByteRangeBody($resource, $ranges, $boundary, $contentType, $contentLength);
        
        $headers = "headers\r\n";
        $destination = fopen('php://memory', 'r+');
        $writer = new MultiPartByteRangeWriter($destination, $headers, $body);
        $writer->setGranularity(200);
        $writer->write();
        
        fclose($destination);
        
        $writer->write();
    }
    
}

