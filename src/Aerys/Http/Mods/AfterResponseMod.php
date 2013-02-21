<?php

namespace Aerys\Http\Mods;

use Aerys\Http\HttpServer;

interface AfterResponseMod extends Mod {
    function afterResponse(HttpServer $server, $requestId);
}
