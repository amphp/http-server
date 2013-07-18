<?php

namespace Aerys\Config;

abstract class ModConfigLauncher extends ConfigLauncher {
    
    abstract function getModPriorityMap();
    
}
