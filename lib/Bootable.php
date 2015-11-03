<?php

namespace Aerys;

interface Bootable {
    /** May return an instance of Middleware and/or callable to be used instead of the class implementing Bootable (which may also implement Middleware and/or be callable) */
    function boot(Server $server, Logger $logger);
}
