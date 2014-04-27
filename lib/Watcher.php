<?php

namespace Aerys;

/**
 * Watchers manage server workers (forks, processes or threads depending on the environment).
 * 
 * Watchers respawn server workers if they fatal out and signal them to gracefully shutdown
 * when the server is stopped or a reload is triggered. Watchers are the front-facing interface
 * of the Aerys binary.
 */
interface Watcher {
    public function watch(BinOptions $binOptions);
}
