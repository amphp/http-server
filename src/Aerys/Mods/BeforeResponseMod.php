<?php

namespace Aerys\Mods;

interface ModBeforeResponse {
    function beforeResponse($clientId, $requestId);
}
