<?php

namespace Aerys\Mods\Expect;

use Auryn\Injector,
    Aerys\Config\ModConfigLauncher;

class ModExpectLauncher extends ModConfigLauncher {
    
    private $modClass = 'Aerys\Mods\Expect\ModExpect';
    private $priorityMap = [
        'onHeaders' => 60
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
