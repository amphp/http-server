<?php

namespace Aerys\Test\Framework;

use Aerys\Framework\Bootstrapper,
    Aerys\Server;

class BootstrapperTest extends \PHPUnit_Framework_TestCase {
    
    function testSetServerOptionDelegate() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['setOption'], [$reactor]);
        $server->expects($this->once())
               ->method('setOption')
               ->with('key', 'val');
        
        $injector = new \Auryn\Provider;
        $injector->alias('Alert\Reactor', get_class($reactor));
        $injector->share($reactor);
        $injector->alias('Aerys\Server', get_class($server));
        $injector->share($server);
        
        $bootstrapper = new Bootstrapper($injector);
        $this->assertSame($bootstrapper, $bootstrapper->setServerOption('key', 'val'));
    }
    
    function testSetAllServerOptionsDelegate() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['setAllOptions'], [$reactor]);
        $server->expects($this->once())
               ->method('setAllOptions')
               ->with([]);
        
        $injector = new \Auryn\Provider;
        $injector->alias('Alert\Reactor', get_class($reactor));
        $injector->share($reactor);
        $injector->alias('Aerys\Server', get_class($server));
        $injector->share($server);
        
        $bootstrapper = new Bootstrapper($injector);
        $this->assertSame($bootstrapper, $bootstrapper->setAllServerOptions([]));
    }
    
    function testRun() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['start'], [$reactor]);
        $server->expects($this->once())
               ->method('start');
        
        $injector = new \Auryn\Provider;
        $injector->alias('Alert\Reactor', get_class($reactor));
        $injector->share($reactor);
        $injector->alias('Aerys\Server', get_class($server));
        $injector->share($server);
        
        $bootstrapper = new Bootstrapper($injector);
        $bootstrapper->run();
    }
    
    /**
     * @expectedException \Aerys\Framework\ConfigException
     */
    function testRunThrowsOnServerStartFailure() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = new Server($reactor);
        
        $injector = new \Auryn\Provider;
        $injector->alias('Alert\Reactor', get_class($reactor));
        $injector->share($reactor);
        $injector->share($server);
        
        $bootstrapper = new Bootstrapper($injector);
        $bootstrapper->run();
    }
    
}
