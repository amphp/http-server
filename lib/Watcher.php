<?php

namespace Aerys;

interface Watcher {
    /**
     * Watch/manage a server instance
     *
     * @param array $cliOptions
     * @return \Generator
     */
    public function watch(array $cliOptions): \Generator;
}
