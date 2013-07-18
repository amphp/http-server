<?php

namespace Aerys\Mods\Limit;

use Auryn\Injector,
    Aerys\Config\ModConfigLauncher;

class ModLimitLauncher extends ModConfigLauncher {
    
    private $modClass = 'Aerys\Mods\Limit\ModLimit';
    private $priorityMap = [
        'onHeaders' => 15
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
