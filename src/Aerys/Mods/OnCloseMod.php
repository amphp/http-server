<?php

namespace Aerys\Mods;

use Aerys\Engine\EventBase;

interface OnCloseMod {
    function onClose($clientId);
}
