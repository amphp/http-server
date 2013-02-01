<?php

namespace Aerys\Mods;

interface OnResponseMod {
    function onResponse($clientId, $requestId);
}
