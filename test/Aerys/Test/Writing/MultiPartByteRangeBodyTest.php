<?php

namespace Aerys\Test\Writing;

use Aerys\Writing\MultiPartByteRangeBody;

class MultiPartByteRangeBodyTest extends \PHPUnit_Framework_TestCase {
    
    function testRangeIteration() {
        $resource = fopen('php://memory', 'r+');
        $ranges = [
            [0, 42],
            [50, 99]
        ];
        $boundary = 'fsjahfjlskdafksd';
        $contentType = 'text/plain';
        $contentLength = 100;
        
        $body = new MultiPartByteRangeBody($resource, $ranges, $boundary, $contentType, $contentLength);
        
        $this->assertEquals(0, $body->key());
        
        $iteratedRanges = [];
        foreach ($body as $range) {
            $iteratedRanges[] = $range;
        }
        
        $this->assertEquals($ranges, $iteratedRanges);
    }
    
    function testGetResource() {
        $resource = fopen('php://memory', 'r+');
        $ranges = [
            [0, 42],
            [50, 99]
        ];
        $boundary = 'fsjahfjlskdafksd';
        $contentType = 'text/plain';
        $contentLength = 100;
        
        $body = new MultiPartByteRangeBody($resource, $ranges, $boundary, $contentType, $contentLength);
        
        $this->assertEquals($resource, $body->getResource());
    }
    
    function testGetBoundary() {
        $resource = fopen('php://memory', 'r+');
        $ranges = [
            [0, 42],
            [50, 99]
        ];
        $boundary = 'fsjahfjlskdafksd';
        $contentType = 'text/plain';
        $contentLength = 100;
        
        $body = new MultiPartByteRangeBody($resource, $ranges, $boundary, $contentType, $contentLength);
        
        $this->assertEquals($boundary, $body->getBoundary());
    }
    
    function testGetContentType() {
        $resource = fopen('php://memory', 'r+');
        $ranges = [
            [0, 42],
            [50, 99]
        ];
        $boundary = 'fsjahfjlskdafksd';
        $contentType = 'text/plain';
        $contentLength = 100;
        
        $body = new MultiPartByteRangeBody($resource, $ranges, $boundary, $contentType, $contentLength);
        
        $this->assertEquals($contentType, $body->getContentType());
    }
    
    function testGetContentLength() {
        $resource = fopen('php://memory', 'r+');
        $ranges = [
            [0, 42],
            [50, 99]
        ];
        $boundary = 'fsjahfjlskdafksd';
        $contentType = 'text/plain';
        $contentLength = 100;
        
        $body = new MultiPartByteRangeBody($resource, $ranges, $boundary, $contentType, $contentLength);
        
        $this->assertEquals($contentLength, $body->getContentLength());
    }
    
}

