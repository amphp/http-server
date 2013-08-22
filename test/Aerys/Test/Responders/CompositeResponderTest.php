<?php

namespace Aerys\Test\Responders;

use Aerys\Responders\CompositeResponder;

class CompositeResponderTest extends \PHPUnit_Framework_TestCase {

    /**
     * @expectedException \InvalidArgumentException
     */
    function testConstructorThrowsOnEmptyResponderArray() {
        $responder = new CompositeResponder([]);
    }
    
    /**
     * @expectedException \InvalidArgumentException
     */
    function testConstructorThrowsOnInvalidResponderArrayValue() {
        $responder = new CompositeResponder([
            $this->getMock('Aerys\Responders\AsgiResponder'),
            new \StdClass
        ]);
    }
    
    /**
     * @dataProvider provideResponderExpectations
     */
    function testResponse($responders, $expectedAsgiResponse) {
        $responder = new CompositeResponder($responders);
        $asgiResponse = $responder->__invoke($asgiEnv = [], $requestId = 42);
        $this->assertEquals($expectedAsgiResponse, $asgiResponse);
    }

    function provideResponderExpectations() {
        $return = [];
        $asgiEnv = [];
        $requestId = 42;
        
        $asgiResponseOk = [200, 'OK', [], 'hello, world'];
        $asgiResponseNotFound = [
            $status = 404,
            $reason = 'Method Not Allowed',
            $headers = [],
            $body = '<html><body><h1>404 Not Found</h1></body></html>'
        ];
        
        // 0 -------------------------------------------------------------------------------------->
        
        $responder1 = $this->getMock('Aerys\Responders\AsgiResponder');
        $responder1->expects($this->once())
                   ->method('__invoke')
                   ->with($asgiEnv, $requestId)
                   ->will($this->returnValue($asgiResponseNotFound));
        
        
        $responder2 = $this->getMock('Aerys\Responders\AsgiResponder');
        $responder2->expects($this->once())
                   ->method('__invoke')
                   ->with($asgiEnv, $requestId)
                   ->will($this->returnValue($asgiResponseOk));
        
        $return[] = [[$responder1, $responder2], $expectedResponse = $asgiResponseOk];
        
        // 1 -------------------------------------------------------------------------------------->
        
        $responder1 = $this->getMock('Aerys\Responders\AsgiResponder');
        $responder1->expects($this->once())
                   ->method('__invoke')
                   ->with($asgiEnv, $requestId)
                   ->will($this->returnValue(NULL));
        
        
        $responder2 = $this->getMock('Aerys\Responders\AsgiResponder');
        $responder2->expects($this->once())
                   ->method('__invoke')
                   ->with($asgiEnv, $requestId)
                   ->will($this->returnValue($asgiResponseOk));
        
        $return[] = [[$responder1, $responder2], $expectedResponse = NULL];
        
        // 2 -------------------------------------------------------------------------------------->
        
        $asgiResponseNotFound2 = $asgiResponseNotFound;
        $asgiResponseNotFound2[3] = 'different value';
        $responder1 = $this->getMock('Aerys\Responders\AsgiResponder');
        $responder1->expects($this->once())
                   ->method('__invoke')
                   ->with($asgiEnv, $requestId)
                   ->will($this->returnValue($asgiResponseNotFound2));
        
        
        $responder2 = $this->getMock('Aerys\Responders\AsgiResponder');
        $responder2->expects($this->once())
                   ->method('__invoke')
                   ->with($asgiEnv, $requestId)
                   ->will($this->returnValue($asgiResponseNotFound2));
        
        $return[] = [[$responder1, $responder2], $expectedResponse = $asgiResponseNotFound];
        
        // x -------------------------------------------------------------------------------------->
        
        return $return;
    }

}
