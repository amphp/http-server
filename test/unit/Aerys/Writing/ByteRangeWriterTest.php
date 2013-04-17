<?php

use Aerys\Writing\ByteRangeWriter,
    Aerys\Writing\ByteRangeBody;

class ByteRangeWriterTest extends PHPUnit_Framework_TestCase {
    
    function testWrite() {
        $destination = fopen('php://memory', 'r+');
        $bodyResource = fopen('php://memory', 'r+');
        fwrite($bodyResource, 'test');
        rewind($bodyResource);
        
        $headers = 'headers';
        $body = new ByteRangeBody($bodyResource, $startPos = 0, $endPos = 3);
        $expectedWrite = $headers . 'tes'; // <-- the "t" is purposely omitted
        
        $writer = new ByteRangeWriter($destination, $headers, $body);
        $writer->setGranularity(1);
        
        while (!$writer->write());
        
        rewind($destination);
        
        $this->assertEquals($expectedWrite, stream_get_contents($destination));
    }
    
}
