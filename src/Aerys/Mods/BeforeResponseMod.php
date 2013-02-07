<?php

namespace Aerys\Mods;

interface BeforeResponseMod {
    function beforeResponse($clientId, $requestId);
}
