<?php

namespace Aerys\Mods;

interface OnRequestMod {

    function onRequest($requestId);
    function getOnRequestPriority();
    
}

