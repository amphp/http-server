<?php

namespace Aerys;

use Psr\Log\LoggerInterface as PsrLogger;

interface Bootable {
    /**
     * @param Server $server
     * @param PsrLogger $logger
     * @return Middleware|callable|null to be used instead of the class implementing Bootable (which may also implement Middleware and/or be callable)
     */
    function boot(Server $server, PsrLogger $logger);
}
