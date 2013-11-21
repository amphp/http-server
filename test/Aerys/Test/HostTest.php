<?php

namespace Aerys\Test;

use Aerys\Host;

class HostTest extends \PHPUnit_Framework_TestCase {

    function testCtorAcceptsWildcardAddress() {
        $address = '*';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};

        $host = new Host($address, $port, $name, $handler);
        $this->assertEquals('*', $host->getAddress());
    }

    function testCtorAcceptsIpv6Address() {
        $address = '[fe80::1]';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};

        $host = new Host($address, $port, $name, $handler);
        $this->assertEquals('[fe80::1]', $host->getAddress());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    function testCtorThrowsOnInvalidAddress() {
        $address = 'not an IP';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    function testCtorThrowsOnInvalidPort() {
        $address = '*';
        $port = 65536;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);
    }

    /**
     * @requires extension openssl
     */
    function testSetEncryptionContext() {
        $address = '*';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);
        $host->setEncryptionContext([
            'local_cert' => FIXTURE_DIR . '/vfs/misc/cert.pem.placeholder',
            'passphrase' => 'some pass'
        ]);
    }

    /**
     * @requires extension openssl
     */
    function testSetEncryptionContextAcceptsEmptyArray() {
        $address = '*';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);
        $host->setEncryptionContext([]);
    }

    /**
     * @requires extension openssl
     * @dataProvider provideBadEncryptionArrays
     * @expectedException \InvalidArgumentException
     */
    function testSetEncryptionContextThrowsOnInvalidArray($tlsConfig) {
        $address = '*';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);
        $host->setEncryptionContext($tlsConfig);
    }

    function provideBadEncryptionArrays() {
        return [
            [['local_cert' => '/some/path']], // <-- missing passphrase
            [['passphrase' => '42 is not a real passphrase']], // <-- missing local_cert
            [['local_cert' => '/bad/path', 'passphrase' => 'doesnt matter']] // <-- nonexistent cert
        ];
    }

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

    /**
     * @dataProvider provideHostIdMatchExpectations
     */
    function testMatches($host, $hostIdStr, $expectedResult) {
        $this->assertSame($host->matches($hostIdStr), $expectedResult);
    }

    function provideHostIdMatchExpectations() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);

        return [
            [$host, '*', TRUE],
            [$host, '*:*', TRUE],
            [$host, '127.0.0.1:*', TRUE],
            [$host, '*:80', TRUE],
            [$host, 'localhost:*', TRUE],
            [$host, 'localhost:80', TRUE],
            [$host, 'otherhost.com:80', FALSE],
            [$host, 'localhost:443', FALSE],
            [$host, '127.0.0.1:443', FALSE],
            [$host, '*:443', FALSE],
        ];
    }

    /**
     * @dataProvider provideInvalidPriorityMapKeys
     * @expectedException \DomainException
     */
    function testRegisterModThrowsOnInvalidPriorityMapKey($priorityMap) {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);
        $mod = $this->getMock('Aerys\Mods\OnHeadersMod');
        $host->registerMod($mod, $priorityMap);
    }

    function provideInvalidPriorityMapKeys() {
        return [
            [['badKey' => 50]],
            [['onSomething' => 99]]
        ];
    }

    /**
     * @dataProvider provideInvalidModObjects
     * @expectedException \InvalidArgumentException
     */
    function testRegisterModThrowsOnInvalidMod($badMod) {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);
        $host->registerMod($badMod);
    }

    function provideInvalidModObjects() {
        return [
            [new \StdClass],
            [[]],
            ['not a mod']
        ];
    }

    function testModPrioritySortOnHeaders() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);

        $mod1 = $this->getMock('Aerys\Mods\OnHeadersMod');
        $host->registerMod($mod1, ['onHeaders' => 99]);

        $mod2 = $this->getMock('Aerys\Mods\OnHeadersMod');
        $host->registerMod($mod2, ['onHeaders' => 1]);

        $orderedMods = $host->getOnHeadersMods();
        $this->assertSame($mod2, array_shift($orderedMods));
        $this->assertSame($mod1, array_shift($orderedMods));
    }

    function testModPrioritySortBeforeResponse() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);

        $mod1 = $this->getMock('Aerys\Mods\BeforeResponseMod');
        $host->registerMod($mod1, ['beforeResponse' => 99]);

        $mod2 = $this->getMock('Aerys\Mods\BeforeResponseMod');
        $host->registerMod($mod2, ['beforeResponse' => 1]);

        $orderedMods = $host->getBeforeResponseMods();
        $this->assertSame($mod2, array_shift($orderedMods));
        $this->assertSame($mod1, array_shift($orderedMods));
    }

    function testModPrioritySortAfterResponse() {
        $address = '127.0.0.1';
        $port = 80;
        $name = 'localhost';
        $handler = function(){};
        $host = new Host($address, $port, $name, $handler);

        $mod1 = $this->getMock('Aerys\Mods\AfterResponseMod');
        $host->registerMod($mod1, ['afterResponse' => 99]);

        $mod2 = $this->getMock('Aerys\Mods\AfterResponseMod');
        $host->registerMod($mod2, ['afterResponse' => 1]);

        $orderedMods = $host->getAfterResponseMods();
        $this->assertSame($mod2, array_shift($orderedMods));
        $this->assertSame($mod1, array_shift($orderedMods));
    }

}
