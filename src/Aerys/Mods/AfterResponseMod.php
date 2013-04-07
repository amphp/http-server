<?php

namespace Aerys\Mods;

interface AfterResponseMod {

    function afterResponse($requestId);
    function getAfterResponsePriority();
    
}

