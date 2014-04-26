<?php

namespace Aerys\DocRoot;

use Amp\Thread;

class StatTask extends \Stackable {
    private $path;

    public function __construct($path) {
        $this->path = $path;
    }

    public function run() {
        $rootDir = $this->worker->rootDir;
        $rawPath = $rootDir . $this->path;
        $path = realpath($rawPath);

        // If the path doesn't exist we're finished here
        if ($path === FALSE) {
            $this->worker->registerResult(Thread::SUCCESS, FALSE);
            return;
        }

        // Windows seems to bork without this
        $path = str_replace('\\', '/', $path);

        // Protect against dot segment path traversal above the document root
        if (strpos($path, $rootDir) !== 0) {
            $this->worker->registerResult(Thread::SUCCESS, FALSE);
            return;
        }

        // Look for index filename matches if this is a directory path
        if (is_dir($path) && $this->worker->indexes) {
            $path = $this->coalesceIndexPath($path, $this->worker->indexes);
        }

        $stat = stat($path);
        $inode = $stat[1];
        $size = $stat[7];
        $mtime = $stat[9];
        $etagFlags = $this->worker->etagFlags;
        $etagBase = $path . $mtime;
        $etag = $etagFlags ? $this->generateEtag($etagFlags, $etagBase, $size, $inode) : NULL;
        $buffer = ($size > $this->worker->maxCacheEntrySize) ? NULL : $this->bufferContent($path);

        $result = [$path, $size, $mtime, $etag, $buffer];

        $this->worker->registerResult(Thread::SUCCESS, $result);

        clearstatcache(TRUE, $rawPath);
    }

    public function coalesceIndexPath($path, $indexes) {
        $dir = rtrim($path, '/');
        foreach ($indexes as $filename) {
            $coalescedPath = $dir . '/' . $filename;
            if (file_exists($coalescedPath)) {
                clearstatcache(TRUE, $coalescedPath);
                return $coalescedPath;
            }
        }

        return $path;
    }

    public function bufferContent($path) {
        $content = @file_get_contents($path);

        return ($content === FALSE) ? NULL : $content;
    }

    public function generateEtag($etagFlags, $etagBase, $size, $inode) {
        if ($etagFlags & Etag::SIZE) {
            $etagBase .= $size;
        }

        if ($etagFlags & Etag::INODE) {
            $etagBase .= $inode;
        }

        return md5($etagBase);
    }
}