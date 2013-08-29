<?php

namespace Aerys\Test;

use Aerys\Server, Aerys\Host;

class ServerTest extends \PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException \LogicException
     */
    function testStartThrowsIfNoHostsRegistered() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $server->listen();
    }
    
    function testOptionAccessors() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        
        $options = [
            'errorStream'           => STDERR,
            'maxConnections'        => 2500,
            'maxRequests'           => 150,
            'keepAliveTimeout'      => 5,
            'disableKeepAlive'      => FALSE,
            'maxHeaderBytes'        => 8192,
            'maxBodyBytes'          => 10485760,
            'defaultContentType'    => 'text/html',
            'defaultTextCharset'    => 'utf-8',
            'sendServerToken'       => FALSE,
            'normalizeMethodCase'   => TRUE,
            'autoReasonPhrase'      => TRUE,
            'requireBodyLength'     => TRUE,
            'allowedMethods'        => [],
            'socketSoLingerZero'    => FALSE,
            'defaultHost'           => NULL
        ];
        
        $server->setAllOptions($options);
        
        $options['allowedMethods'] = ['GET', 'HEAD'];
        
        foreach ($options as $key => $value) {
            $this->assertEquals($value, $server->getOption($key));
        }
    }
    
    /**
     * @expectedException DomainException
     */
    function testOptionAssignmentThrowsOnUnknownOption() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $server->setOption('some-totally-invalid-and-nonexistent-option', 42);
    }
    
    /**
     * @expectedException DomainException
     */
    function testOptionRetrievalThrowsOnUnknownOption() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $server->getOption('some-totally-invalid-and-nonexistent-option');
    }
    
    /**
     * @expectedException DomainException
     * @expectedExceptionMessage Invalid default host; unknown host ID: some-value
     */
    function testOptionAssignmentThrowsOnBadDefaultHost() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $server->setOption('defaultHost', 'some-value');
    }
    
    function testOptionAssignmentDefaultHost() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        
        $expectedHostId = 'localhost:80';
        
        $host = new Host($address, $port, $name, $handler);
        $server->addHost($host);
        $server->setOption('defaultHost', 'localhost:80');
        
        $this->assertEquals($expectedHostId, $server->getOption('defaultHost'));
    }
    
    function testOptionAssignmentKeepAliveTimeoutAssignsDefaultOnInvalidValue() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        $server->setOption('keepAliveTimeout', 'some-value');
        
        $this->assertEquals(10, $server->getOption('keepAliveTimeout'));
    }
    
    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Cannot enable socketSoLingerZero; PHP sockets extension required
     */
    function testOptionAssignmentSocketSoLingerZeroFailsIfNoSocketsExtension() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        
        $reflObject = new \ReflectionObject($server);
        $reflProperty = $reflObject->getProperty('isExtSocketsEnabled');
        $reflProperty->setAccessible(TRUE);
        $reflProperty->setValue($server, FALSE);
        
        $server->setOption('socketSoLingerZero', TRUE);
    }
    
}
