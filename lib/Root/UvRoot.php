<?php

namespace Aerys\Root;

use Amp\Future;
use Amp\Success;
use Amp\UvReactor;

class UvRoot extends Root {
    private $uvLoop;

    public function __construct(UvReactor $reactor, UvResponderFactory $responderFactory, $rootPath) {
        $this->uvLoop = $reactor->getUnderlyingLoop();
        parent::__construct($reactor, $responderFactory, $rootPath);
    }

    final protected function generateFileEntry($path, array $indexes, callable $onComplete) {
        uv_fs_open($this->uvLoop, $path, \UV::O_RDONLY, 0, function($handle) use ($path, $indexes, $onComplete) {
            if ($handle === -1) {
                return $onComplete(null, null);
            }

            uv_fs_fstat($this->uvLoop, $handle, function($result, $stat) use ($path, $indexes, $handle, $onComplete) {
                if (empty($stat)) {
                    uv_fs_close($this->uvLoop, $handle, function(){});
                    $onComplete(null, null);
                } elseif ($stat['mode'] & \UV::S_IFDIR) {
                    uv_fs_close($this->uvLoop, $handle, function(){});
                    $this->coalesceIndex($path, $indexes, $onComplete);
                } else {
                    $fileEntry = $this->buildFileEntry($path, $handle, $stat);
                    $onComplete(null, $fileEntry);
                }
            });
        });
    }

    private function coalesceIndex($dirPath, array $indexes, callable $onComplete) {
        if (empty($indexes)) {
            return $onComplete(null);
        }

        $indexPath = rtrim($dirPath, "/") . "/" . array_shift($indexes);

        $onOpen = function($handle) use ($dirPath, $onComplete, $indexes, $indexPath) {
            if ($handle === -1) {
                // The path doesn't exist -- try the next index file
                $this->coalesceIndex($dirPath, $indexes, $onComplete);
                return;
            }

            $onStat = function($result, $stat) use ($dirPath, $handle, $onComplete, $indexes, $indexPath) {
                if (empty($stat) || $stat['mode'] & \UV::S_IFDIR) {
                    uv_fs_close($this->uvLoop, $handle, function(){});
                    $this->coalesceIndex($dirPath, $onComplete, $indexes);
                } else {
                    $fileEntry = $this->buildFileEntry($dirPath, $handle, $stat);
                    // We need to update the file entry path or we won't be able to
                    // determine the index file's mime type later
                    $fileEntry->path = $indexPath;
                    $onComplete(null, $fileEntry);
                }
            };

            uv_fs_fstat($this->uvLoop, $handle, $onStat);
        };

        uv_fs_open($this->uvLoop, $indexPath, \UV::O_RDONLY, 0, $onOpen);
    }

    private function buildFileEntry($path, $handle, array $stat) {
        $fileEntry = new UvFileEntry;
        $fileEntry->uvLoop = $this->uvLoop;
        $fileEntry->path = $path;
        $fileEntry->handle = $handle;
        $fileEntry->size = $stat['size'];
        $fileEntry->mtime = $stat['mtime'];
        $fileEntry->inode = $stat['ino'];

        return $fileEntry;
    }

    final protected function bufferFile($handle, $length, callable $onComplete) {
        $uvLoop = $this->uvLoop;
        uv_fs_read($uvLoop, $handle, 0, $length, function($handle, $nread, $buffer) use ($onComplete, $length) {
            $result = ($nread === $length) ? $buffer : false;
            $onComplete($result);
        });
    }
}
