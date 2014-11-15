<?php

namespace Aerys\Root;

use Amp\Reactor;

class NaiveRoot extends Root {
    public function __construct(Reactor $reactor, NaiveResponderFactory $responderFactory, $rootPath) {
        parent::__construct($reactor, $responderFactory, $rootPath);
    }

    final protected function generateFileEntry($path, array $indexes, callable $onComplete) {
        if (!file_exists($path)) {
            $onComplete(null, null);
            return;
        }

        if (is_dir($path) && !($path = $this->coalesceIndexPath($path, $indexes))) {
            $onComplete(null, null);
            return;
        }

        $stat = stat($path);
        clearstatcache(true, $path);

        if (!$handle = @fopen($path, 'r')) {
            $onComplete(new \RuntimeException(
                sprintf('Failed opening file handle: %s', $path)
            ), null);
            return;
        }

        $fileEntry = new FileEntry;
        $fileEntry->path = $path;
        $fileEntry->handle = $handle;
        $fileEntry->size = $stat[7];
        $fileEntry->mtime = $stat[9];
        $fileEntry->inode = $stat[1];

        $onComplete(null, $fileEntry);
    }

    private function coalesceIndexPath($dirPath, $indexes) {
        $dirPath = rtrim($dirPath, '/') . '/';
        foreach ($indexes as $filename) {
            $coalescedPath = $dirPath . $filename;
            if (file_exists($coalescedPath) && is_file($coalescedPath)) {
                return $coalescedPath;
            } else {
                clearstatcache(true, $coalescedPath);
            }
        }
    }

    final protected function bufferFile($handle, $length, callable $onComplete) {
        rewind($handle);
        $onComplete(@stream_get_contents($handle));
    }
}
