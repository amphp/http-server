<?php

namespace Aerys\Documents;

class StatStarterTask extends \Stackable {
    private $rootDir;
    private $etagFlags;
    private $indexes;
    private $maxCacheEntrySize;

    public function __construct($rootDir, $etagFlags, $indexes, $maxCacheEntrySize) {
        $this->rootDir = $rootDir;
        $this->etagFlags = $etagFlags;
        $this->indexes = $indexes;
        $this->maxCacheEntrySize = $maxCacheEntrySize;
    }

    public function run() {
        $this->worker->rootDir = $this->rootDir;
        $this->worker->etagFlags = $this->etagFlags;
        $this->worker->indexes = $this->indexes;
        $this->worker->maxCacheEntrySize = $this->maxCacheEntrySize;
    }
}
