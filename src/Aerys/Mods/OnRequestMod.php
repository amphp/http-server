<?php

namespace Aerys\Mods;

use Aerys\Server;

interface OnRequestMod extends Mod {
    function onRequest(Server $server, $requestId);
}
