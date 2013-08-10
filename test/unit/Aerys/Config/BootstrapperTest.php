<?php

use Aerys\Config\Bootstrapper;

class BootstrapperTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException InvalidArgumentException
     * @dataProvider provideInvalidModLauncherClasses
     */
    function testMapModLauncherThrowsOnInvalidArgs($badArg) {
        $modKey = 'test';
        $bootstrapper = new Bootstrapper;
        $bootstrapper->mapModLauncher($modKey, $badArg);
    }
    
    function provideInvalidModLauncherClasses() {
        return [
            [new StdClass],
            [TRUE],
            ['SomeClassNameThatCantPossiblyExist_______________________________']
        ];
    }
    
    function testMapModLauncherReturnsBootstrapperInstance() {
        $bootstrapper = new Bootstrapper;
        $result = $bootstrapper->mapModLauncher('log', '\Aerys\Mods\Log\ModLogLauncher');
        $this->assertSame($bootstrapper, $result);
    }
    
}
