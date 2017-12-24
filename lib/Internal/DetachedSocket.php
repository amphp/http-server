<?php

namespace Aerys\Internal;

use Amp\Socket\ServerSocket;

class DetachedSocket extends ServerSocket {
    /** @var callable */
    private $clearer;

    /**
     * @param callable $clearer
     * @param resource $resource
     * @param int $chunkSize
     */
    public function __construct(callable $clearer, $resource, int $chunkSize = 65536) {
        parent::__construct($resource, $chunkSize);
        $this->clearer = $clearer;
    }

    public function __destruct() {
        ($this->clearer)();
    }
}
