<?php

namespace Aerys\Handlers;

use Aerys\Server,
    Aerys\Engine\EventBase;

interface InitHandler extends Handler {
    function init(Server $server, EventBase $eventBase);
}
