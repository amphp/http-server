<?php

namespace Aerys\Mods\ErrorPages;

use Auryn\Injector,
    Aerys\Config\ModConfigLauncher;

class ModErrorPagesLauncher extends ModConfigLauncher {
    
    private $modClass = 'Aerys\Mods\ErrorPages\ModErrorPages';
    private $priorityMap = [
        'beforeResponse' => 50
    ];
    
    function launch(Injector $injector) {
        return $injector->make($this->modClass, [
            ':config' => $this->getConfig()
        ]);
    }
    
    function getModPriorityMap() {
        return $this->priorityMap;
    }
    
}
