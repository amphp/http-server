<?php

namespace Aerys\Mods\SendFile;

use Auryn\Injector,
    Aerys\Config\ModConfigLauncher;

class ModSendFileLauncher extends ModConfigLauncher {
    
    private $modClass = 'Aerys\Mods\SendFile\ModSendFile';
    private $docRootHandlerClass = 'Aerys\Handlers\DocRoot\DocRootHandler';
    private $priorityMap = [
        'beforeResponse' => 45
    ];
    
    function launch(Injector $injector) {
        $config = $this->getConfig();
        if (!isset($config['docRoot'])) {
            throw new ModLaunchException(
                'ModSendFile requires a docRoot key'
            );
        } else {
            $docRoot = $config['docRoot'];
            unset($config['docRoot']);
        }
        
        $handler = $injector->make($this->docRootHandlerClass, [
            ':docRoot' => $docRoot,
            ':options' => $config
        ]);
        
        return $injector->make($this->modClass, [
            ':docRootHandler' => $handler
        ]);
    }
    
    function getModPriorityMap() {
        return $this->priorityMap;
    }
    
}
