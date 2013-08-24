<?php

namespace Aerys\Test\Handlers\ReverseProxy;

use Aerys\Responders\ReverseProxy\ReverseProxyResponder;

class ReverseProxyResponderTest extends \PHPUnit_Framework_TestCase {
    
    function testOptionAssignment() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $responder = new ReverseProxyResponder($reactor, $server);
        
        $responder->setAllOptions([
            'lowaterconnectionmin' => 4,
            'hiwaterconnectionmax' => 100,
            'maxPendingRequests' => 1500,
            'proxyPassHeaders' => []
        ]);
    }
    
    /**
     * @expectedException \DomainException
     */
    function testOptionAssignmentThrowsOnUnknownKey() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $responder = new ReverseProxyResponder($reactor, $server);
        
        $responder->setOption('some unknown key', 42);
    }
    
    /**
     * @dataProvider provideInvalidBackendUris
     * @expectedException \InvalidArgumentException
     */
    function testAddBackendThrowsOnMalformedUri($badUri) {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $responder = new ReverseProxyResponder($reactor, $server);
        
        $responder->addBackend($badUri);
    }
    
    function provideInvalidBackendUris() {
        return [
            ['some invalid uri'],
            [new \StdClass],
            [TRUE]
        ];
    }
    
    function testServiceUnavailableResponseIfNoBackendsSpecified() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $responder = new ReverseProxyResponder($reactor, $server);
        
        $asgiResponse = $responder(['REQUEST_URI' => '/'], 42);
        $status = $asgiResponse[0];
        $this->assertEquals(503, $status);
    }
}
