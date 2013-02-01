<?php

namespace Aerys\Mods;

interface OnHeadersMod {
    function onHeaders($clientId, $requestId);
}
