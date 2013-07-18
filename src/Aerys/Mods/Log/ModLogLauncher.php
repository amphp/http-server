<?php

namespace Aerys\Mods\Log;

use Auryn\Injector,
    Aerys\Config\ModConfigLauncher;

class ModLogLauncher extends ModConfigLauncher {
    
    private $modClass = 'Aerys\Mods\Log\ModLog';
    private $priorityMap = [
        'afterResponse' => 51 // (Area)
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
