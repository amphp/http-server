<?php

namespace Aerys\Config;

use Auryn\Injector;

abstract class ConfigLauncher {
    
    private $config;
    
    final function __construct(array $config) {
        if (empty($config)) {
            throw new ConfigException(
                'Launcher configuration must not be empty'
            );
        } else {
            $this->config = $config;
        }
    }
    
    final function getConfig() {
        return $this->config;
    }
    
    abstract function launch(Injector $injector);
}
