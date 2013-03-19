<?php

use Aerys\MessageWriter,
    Aerys\Response;

class MessageWriterTest extends PHPUnit_Framework_TestCase {
    
    public function provideWriteExpectations() {
        $returnArr = array();
        
        // 0 -------------------------------------------------------------------------------------->
        $protocol = '1.1';
        $status = 200;
        $reason = '';
        $body = 'Woot!';
        $headers = array(
            'Content-Length' => strlen($body),
            'Content-Type' => 'text/plain'
        );
        
        $expected = 
            "HTTP/1.1 200\r\n" .
            "Content-Length: " . strlen($body) . "\r\n" .
            "Content-Type: text/plain\r\n" .
            "\r\n" .
            $body
        ;
        
        $returnArr[] = array($protocol, $status, $reason, $headers, $body, $expected);
        
        // 1 -------------------------------------------------------------------------------------->
        $protocol = '1.1';
        $status = 100;
        $reason = 'Continue';
        $body = NULL;
        $headers = array();
        
        $expected = "HTTP/1.1 100 Continue\r\n\r\n";
        
        $returnArr[] = array($protocol, $status, $reason, $headers, $body, $expected);
        
        // 2 -------------------------------------------------------------------------------------->
        $protocol = '1.1';
        $status = 200;
        $reason = '';
        $body = 'Woot!';
        $headers = array(
            'Content-Length' => strlen($body),
            'Content-Type' => 'text/plain',
            'Set-Cookie' => array('cookie1', 'cookie2', 'cookie3')
        );
        
        $expected = 
            "HTTP/1.1 200\r\n" .
            "Content-Length: " . strlen($body) . "\r\n" .
            "Content-Type: text/plain\r\n" .
            "Set-Cookie: cookie1\r\n" . 
            "Set-Cookie: cookie2\r\n" . 
            "Set-Cookie: cookie3\r\n" . 
            "\r\n" .
            $body
        ;
        
        $returnArr[] = array($protocol, $status, $reason, $headers, $body, $expected);
        
        // 3 -------------------------------------------------------------------------------------->
        $protocol = '1.1';
        $status = 200;
        $reason = '';
        $bodyStr = 'The cake is a lie';
        $body = fopen('php://memory', 'r+');
        fwrite($body, $bodyStr);
        rewind($body);
        
        $headers = array(
            'Content-Length' => strlen($bodyStr),
            'Content-Type' => 'text/plain'
        );
        
        $expected = 
            "HTTP/1.1 200\r\n" .
            "Content-Length: " . strlen($bodyStr) . "\r\n" .
            "Content-Type: text/plain\r\n" .
            "\r\n" .
            $bodyStr
        ;
        
        $returnArr[] = array($protocol, $status, $reason, $headers, $body, $expected);
        
        // 4 -------------------------------------------------------------------------------------->
        $protocol = '1.1';
        $status = 200;
        $reason = '';
        $bodyStr = str_repeat('X', 32768);
        $body = fopen('php://memory', 'r+');
        fwrite($body, $bodyStr);
        rewind($body);
        
        $headers = array(
            'Content-Length' => strlen($bodyStr),
            'Content-Type' => 'text/plain'
        );
        
        $expected = 
            "HTTP/1.1 200\r\n" .
            "Content-Length: " . strlen($bodyStr) . "\r\n" .
            "Content-Type: text/plain\r\n" .
            "\r\n" .
            $bodyStr
        ;
        
        $returnArr[] = array($protocol, $status, $reason, $headers, $body, $expected);
        
        // 5 -------------------------------------------------------------------------------------->
        $protocol = '1.1';
        $status = 200;
        $reason = 'OK';
        $body = new ArrayIterator(['chunk1', 'chunk2', 'chunk3']);
        
        $headers = array(
            'Transfer-Encoding' => 'chunked',
            'Content-Type' => 'text/plain'
        );
        
        $expected = 
            "HTTP/1.1 200 OK\r\n" .
            "Transfer-Encoding: chunked\r\n" .
            "Content-Type: text/plain\r\n" .
            "\r\n" .
            "6\r\nchunk1\r\n" . 
            "6\r\nchunk2\r\n" . 
            "6\r\nchunk3\r\n" . 
            "0\r\n\r\n"
        ;
        
        $returnArr[] = array($protocol, $status, $reason, $headers, $body, $expected);
        
        // 6 -------------------------------------------------------------------------------------->
        $protocol = '1.0';
        $status = 200;
        $reason = 'OK';
        $body = new ArrayIterator(['chunk1', 'chunk2', 'chunk3']);
        
        $headers = array(
            'Connection' => 'close',
            'Content-Type' => 'text/plain'
        );
        
        $expected = 
            "HTTP/1.0 200 OK\r\n" .
            "Connection: close\r\n" .
            "Content-Type: text/plain\r\n" .
            "\r\n" .
            "chunk1" . 
            "chunk2" . 
            "chunk3"
        ;
        
        $returnArr[] = array($protocol, $status, $reason, $headers, $body, $expected);
        
        // x -------------------------------------------------------------------------------------->
        
        return $returnArr;
    }
    
    /**
     * @dataProvider provideWriteExpectations
     */
    public function testWrite($protocol, $status, $reason, $headers, $body, $expected) {
        $destination = fopen('php://memory', 'r+');
        $writer = new MessageWriter($destination);
        $message = (new Response)->setAll($protocol, $status, $reason, $headers, $body);
        $writer->enqueue($message);
        
        while(!$writer->write()) {
            continue;
        }
        
        rewind($destination);
        
        $this->assertEquals($expected, stream_get_contents($destination));
    }
    
}




















