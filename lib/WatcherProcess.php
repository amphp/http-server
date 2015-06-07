<?php

namespace Aerys;

use Amp\Reactor;

class WatcherProcess extends Process {

    public function __construct() {
        die("\nDebug mode flag required (-d)\n\n");
    }

    protected function doStart(Console $console): \Generator {
        // @TODO
    }

    protected function doStop(): \Generator {
        // @TODO
    }
}
