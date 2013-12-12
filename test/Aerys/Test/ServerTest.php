<?php

namespace Aerys\Test;

use Aerys\Server, Aerys\Host;

class ServerTest extends \PHPUnit_Framework_TestCase {

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
            'defaultHost'           => 'mysite.com:80',
            'defaultContentType'    => 'text/html',
            'defaultTextCharset'    => 'utf-8',
            'sendServerToken'       => FALSE,
            'normalizeMethodCase'   => TRUE,
            'autoReasonPhrase'      => TRUE,
            'requireBodyLength'     => TRUE,
            'allowedMethods'        => [],
            'socketSoLingerZero'    => FALSE
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
