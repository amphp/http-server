<?php

namespace Aerys\Http\Mods;

interface AfterResponseMod {
    function afterResponse($requestId);
}

