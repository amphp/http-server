<?php

namespace Aerys\Root;

use Amp\Future;
use Amp\Success;
use Amp\UvReactor;

// @TODO

class UvRoot {
    private $reactor;
    private $uvLoop;

    /**
     * @param resource $uvLoop The UV loop resource underlying the event reactor
     */
    public function __construct(UvReactor $reactor) {
        $this->reactor = $reactor;
        $this->uvLoop = $reactor->getLoop();
    }

    /**
     * Retrieve file stat info
     *
     * @param string $path
     * @return After\Future
     */
    public function stat($path) {
        $future = new Future($this->reactor);

        uv_fs_open($this->uvLoop, $path, \UV::O_RDONLY, 0, function($resource) use ($future, $path) {
            if ($resource === -1) {
                $future->succeed(FALSE);
                return;
            }

            uv_fs_fstat($this->uvLoop, $resource, function($result, $stat) use ($future, $path) {
                if ($stat) {
                    $future->succeed([$path, $stat['size'], $stat['mtime']]);
                } else {
                    $future->fail(new \RuntimeException(
                        sprintf('File stat failed: %s', $path)
                    ));
                }
            });
        });

        return $future;
    }
}
