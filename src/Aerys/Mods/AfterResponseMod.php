<?php

namespace Aerys\Mods;

interface AfterResponseMod {
    function afterResponse($clientId, $requestId);
}
