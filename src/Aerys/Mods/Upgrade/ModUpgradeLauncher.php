<?php

namespace Aerys\Mods\Upgrade;

use Auryn\Injector,
    Aerys\Config\ModConfigLauncher;

class ModUpgradeLauncher extends ModConfigLauncher {
    
    private $modClass = 'Aerys\Mods\Upgrade\ModUpgrade';
    private $priorityMap = [
        'afterResponse' => 30
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
