<?php

namespace Aerys;

interface Bootable {
    function boot(Server $server, Logger $logger);
}
