<?php

namespace Aerys;

use Psr\Log\LoggerInterface as PsrLogger;

interface Bootable {
    /**
     * @param Server $server
     * @param PsrLogger $logger
     * @return Filter|callable|null to be used instead of the class implementing Bootable (which may also implement Filter and/or be callable)
     */
    public function boot(Server $server, PsrLogger $logger);
}
