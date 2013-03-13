<?php

namespace Aerys\Http\Mods;

interface BeforeResponseMod {
    function beforeResponse($requestId);
}

