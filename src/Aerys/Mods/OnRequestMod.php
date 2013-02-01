<?php

namespace Aerys\Mods;

interface OnRequestMod {
    function onRequest($clientId, $requestId);
}
