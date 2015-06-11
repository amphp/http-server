<?php

namespace Aerys;

use Amp\Reactor;

interface Bootable {
    function boot(Reactor $reactor, Server $server, Logger $logger);
}
