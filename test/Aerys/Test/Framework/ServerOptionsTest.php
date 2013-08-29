<?php

namespace Aerys\Test\Framework;

use Aerys\Framework\ServerOptions;

class ServerOptionsTest extends \PHPUnit_Framework_TestCase {

    /**
     * @expectedException \DomainException
     */
    function testSetOptionThrowsOnInvalidKey() {
        $so = new ServerOptions;
        $so->setOption('some_nonexistent_server_option_key', 42);
    }

    /**
     * @expectedException \DomainException
     */
    function testSetAllOptionsThrowsOnInvalidKey() {
        $so = new ServerOptions;
        $so->setAllOptions([
            'some_nonexistent_server_option_key' => 42,
            'maxConnections' => 1000
        ]);
    }

    function testOptionAccessors() {
        $so = new ServerOptions;
        $this->assertSame($so, $so->setOption('maxConnections', 42));
        $this->assertSame(42, $so->getOption('maxConnections'));
    }

    function testSetAllOptions() {
        $so = new ServerOptions;
        $options = [
            'maxconnections' => 42
        ];

        $this->assertSame($so, $so->setAllOptions($options));
        $this->assertEquals($options, $so->getAllOptions());
    }

    function testOffsetExists() {
        $so = new ServerOptions;
        $this->assertTrue(isset($so['maxConnections']));
        $this->assertFalse(isset($so['some_nonexistent_server_option_key']));
    }

    /**
     * @expectedException \DomainException
     */
    function testOffsetGetThrowsOnInvalidKey() {
        $so = new ServerOptions;
        $var = $so['some_nonexistent_server_option_key'];
    }

    function testGetAllOptions() {
        $so = new ServerOptions;
        $so['maxConnections'] = 42;
        $this->assertEquals(['maxconnections' => 42], $so->getAllOptions());
    }

    function testOffsetUnsetResetsOptionToNull() {
        $so = new ServerOptions;
        $so['maxConnections'] = 42;
        $this->assertEquals(42, $so['maxConnections']);
        unset($so['maxConnections']);
        $this->assertNull($so['maxConnections']);
    }

}
