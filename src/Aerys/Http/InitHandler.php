<?php

namespace Aerys\Http;

use Aerys\Engine\EventBase;

interface InitHandler extends Handler {
    function init(HttpServer $server, EventBase $eventBase);
}
