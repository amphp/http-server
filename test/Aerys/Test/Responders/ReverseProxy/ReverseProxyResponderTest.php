<?php

namespace Aerys\Test\Handlers\ReverseProxy;

use Aerys\Responders\ReverseProxy\ReverseProxyResponder;

class ReverseProxyTest extends \PHPUnit_Framework_TestCase {
    
    function testConstruct() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $handler = new ReverseProxyResponder($reactor, $server);
        $this->assertInstanceOf('Aerys\Responders\ReverseProxy\ReverseProxyResponder', $handler);
    }
    
    function testOptionAssignment() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $handler = new ReverseProxyResponder($reactor, $server);
        
        $handler->setAllOptions([
            'debug' => TRUE,
            'debugColors' => TRUE,
            'maxPendingRequests' => 1500,
            'proxyPassHeaders' => []
        ]);
    }
    
    function testServiceUnavailableResponse() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $handler = new ReverseProxyResponder($reactor, $server);
        
        $asgiResponse = $handler(['REQUEST_URI' => '/'], 42);
        
        $this->assertEquals(503, $asgiResponse[0]);
    }
}
