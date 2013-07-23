<?php

namespace Aerys\Handlers\ReverseProxy;

use Auryn\Injector,
    Aerys\Config\ConfigLauncher,
    Aerys\Config\ConfigException;

class ReverseProxyLauncher extends ConfigLauncher {
    
    private $handlerClass = 'Aerys\Handlers\ReverseProxy\ReverseProxyHandler';
    private $configDefaults = [
        'backends' => NULL,
        'proxyPassHeaders' => NULL,
        'maxPendingRequests' => NULL
    ];
    
    function launch(Injector $injector) {
        $config = array_filter($this->getConfig(), function($x) { return $x !== NULL;});
        $config = array_intersect_key($config, $this->configDefaults);
        $config = array_merge($this->configDefaults, $config);
        
        if (empty($config['backends'])) {
            throw new ConfigException(
                'Backend socket URI array required to launch ReverseProxy'
            );
        } else {
            $backends = $config['backends'];
            unset($config['backends']);
        }
        
        $handler = $injector->make($this->handlerClass, [
            ':backends' => $backends
        ]);
        
        $this->setHandlerOptions($handler, $config);
        
        return $handler;
    }
    
    private function setHandlerOptions(ReverseProxyHandler $handler, array $config) {
        try {
            $handler->setAllOptions($config);
        } catch (\DomainException $optionError) {
            throw new ConfigException(
                'Error encountered launching ReverseProxyHandler',
                $errorNo = 0,
                $optionError
            );
        }
    }
    
}
