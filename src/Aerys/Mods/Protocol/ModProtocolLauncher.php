<?php

namespace Aerys\Mods\Protocol;

use Auryn\Injector,
    Auryn\InjectionException,
    Aerys\Config\ModConfigLauncher,
    Aerys\Config\ConfigException;

class ModProtocolLauncher extends ModConfigLauncher {
    
    private $modClass = 'Aerys\Mods\Protocol\ModProtocol';
    private $priorityMap = [
        'beforeResponse' => 25
    ];
    
    function launch(Injector $injector) {
        $modProtocol = $injector->make($this->modClass);
        
        $config = $this->getConfig();
        
        if (isset($config['options'])) {
            $options = $config['options'];
            unset($config['options']);
            $modProtocol->setAllOptions($options);
        }
        
        if (!(isset($config['handlers']) && is_array($config['handlers']))) {
            throw new ConfigException(
                'ModProtocol configuration must contain a "handlers" key containing an array ' .
                'protocol handler class names'
            );
        }
        
        $injector->share($modProtocol);
        foreach ($config['handlers'] as $handlerClass) {
            $handler = $this->makeHandler($injector, $handlerClass);
            $modProtocol->registerProtocolHandler($handler);
        }
        $injector->unshare(get_class($modProtocol));
        
        return $modProtocol;
    }
    
    private function makeHandler(Injector $injector, $handlerClass) {
        try {
            return $injector->make($handlerClass);
        } catch (InjectionException $injectionError) {
            throw new ConfigException(
                "ProtocolHandler instantiation failed for {$handlerClass}",
                $errorCode = 0,
                $injectionError
            );
        }
    }
    
    function getModPriorityMap() {
        return $this->priorityMap;
    }
    
}
