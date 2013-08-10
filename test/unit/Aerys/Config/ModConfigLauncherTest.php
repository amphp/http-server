<?php

use Aerys\Config\ModConfigLauncher,
    Auryn\Injector;

class ModConfigLauncherTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException Aerys\Config\ConfigException
     */
    function testConstructorThrowsOnEmptyConfigArray() {
        $launcher = new ModConfigLauncherTestImplementation([]);
    }
    
    function testGetConfig() {
        $config = ['test' => 'val'];
        $launcher = new ModConfigLauncherTestImplementation($config);
        $this->assertEquals($config, $launcher->getConfig());
    }
    
}

class ModConfigLauncherTestImplementation extends ModConfigLauncher {
    function launch(\Auryn\Injector $injector) {}
    function getModPriorityMap() {}
}
