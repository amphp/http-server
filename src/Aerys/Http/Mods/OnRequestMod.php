<?php

namespace Aerys\Http\Mods;

use Aerys\Http\HttpServer;

interface OnRequestMod extends Mod {
    function onRequest(HttpServer $server, $requestId);
}
