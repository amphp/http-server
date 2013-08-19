<?php

namespace Aerys\Test;

use Aerys\Host;

class HostTest extends \PHPUnit_Framework_TestCase {

    function testGetId() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};

        $expectedId = 'localhost:80';

        $host = new Host($address, $port, $name, $handler);
        $this->assertEquals($expectedId, $host->getId());
    }

    function testGetAddress() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};

        $host = new Host($address, $port, $name, $handler);
        $this->assertEquals($address, $host->getAddress());
    }

    function testGetPort() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};

        $host = new Host($address, $port, $name, $handler);
        $this->assertEquals($port, $host->getPort());
    }

    function testGetName() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};

        $host = new Host($address, $port, $name, $handler);
        $this->assertEquals($name, $host->getName());
    }

    function testGetHandler() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};

        $host = new Host($address, $port, $name, $handler);
        $this->assertEquals($handler, $host->getHandler());
    }

}
