<?php

namespace Aerys\Mods;

use Aerys\Server;

interface AfterResponseMod extends Mod {
    function afterResponse(Server $server, $requestId);
}
