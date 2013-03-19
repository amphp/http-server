<?php

namespace Aerys\Config;

use Auryn\Injector;

interface AppLauncher {
    function launchApp(Injector $injector);
}

