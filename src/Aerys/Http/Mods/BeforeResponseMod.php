<?php

namespace Aerys\Http\Mods;

use Aerys\Http\HttpServer;

interface BeforeResponseMod extends Mod {
    function beforeResponse(HttpServer $server, $requestId);
}
