<?php

namespace Aerys\Http\Config;

use Auryn\Injector;

interface AppLauncher {
    function launchApp(Injector $injector);
}

