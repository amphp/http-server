<?php

namespace Aerys\Handlers\Websocket\Io;

use Aerys\Handlers\Websocket\Frame;

class FrameQueue extends \SplPriorityQueue {
    
    private $serial = PHP_INT_MAX;
    
    public function insert($frame, $priority) {
        if ($frame instanceof Frame) {
            parent::insert($frame, [$priority, $this->serial--]);
            $this->serial = $this->serial ?: PHP_INT_MAX;
        } else {
            throw new \InvalidArgumentException(
                __CLASS__ . '::insert requires an instance of Aerys\\Ws\\Frame at Argument 1'
            );
        }
    }
    
}

