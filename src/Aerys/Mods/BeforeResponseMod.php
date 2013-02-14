<?php

namespace Aerys\Mods;

use Aerys\Server;

interface BeforeResponseMod extends Mod {
    function beforeResponse(Server $server, $requestId);
}
