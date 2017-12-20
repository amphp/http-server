<?php

namespace Aerys\Test;

use Aerys\Host;
use Aerys\Http1Driver;
use Aerys\Http2Driver;
use PHPUnit\Framework\TestCase;

class HostTest extends TestCase {
    public function getHost() { // we do not want to add to definitions, that's for the Bootstrapper test.
        return (new \ReflectionClass('Aerys\Host'))->newInstanceWithoutConstructor();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid port number 65536; integer in the range 1..65535 required
     */
    public function testThrowsWithBadPort() {
        $this->getHost()->expose("127.0.0.1", 65536);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Invalid IP address
     */
    public function testThrowsWithBadInterface() {
        $this->getHost()->expose("bizzibuzzi", 1025);
    }

    public function testGenericInterface() {
        $host = $this->getHost();
        $host->expose("*", 1025);
        if (Host::separateIPv4Binding()) {
            $this->assertEquals([["0.0.0.0", 1025], ["::", 1025]], $host->export()["interfaces"]);
        } else {
            $this->assertEquals([["::", 1025]], $host->export()["interfaces"]);
        }
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Aerys\Host::use requires a callable action or Bootable or Filter or HttpDriver instance
     */
    public function testBadUse() {
        $this->getHost()->use(1);
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Impossible to define two HttpDriver instances for one same Host; an instance of Aerys\Http1Driver has already been defined as driver
     */
    public function testDriverRedefine() {
        $this->getHost()->use(new Http1Driver)->use(new Http2Driver);
    }
}
