<?php

namespace Aerys\Config;

use Auryn\Injector;

interface Launcher {
    function launchApp(Injector $injector);
}

