<?php

namespace Aerys;

use Amp\{
    Promise,
    PrivateFuture
};

class PromiseStream {
    private $promisors;
    private $index = 0;
    private $fulfill = 0;
    private $isBuffering;
    private $buffer;
    private $bufferPromisor;

    public function __construct() {
        $this->promisors[] = new PrivateFuture;
    }

    public function end(string $data = null) {
        if (isset($data)) {
            $this->sink($data);
        }
        $this->promisors[$this->fulfill++]->succeed();
        if ($this->isBuffering) {
            $this->bufferPromisor->succeed($this->buffer);
            $this->buffer = null;
        }
    }

    public function sink(string $data) {
        if ($this->isBuffering) {
            $this->buffer .= $data;
        } else {
            $this->promisors[$this->fulfill++]->succeed($data);
            $this->promisors[++$this->index] = new PrivateFuture;
        }
    }

    public function stream(): \Generator {
        while ($this->promisors) {
            if ($this->isBuffering) {
                throw new \LogicException(
                    "Cannot stream once buffer() is invoked"
                );
            }
            yield $this->promisors[$this->index]->promise();
            unset($this->promisors[$this->index]);
        }
    }

    public function buffer(): Promise {
        $this->isBuffering = true;
        $this->bufferPromisor = new PrivateFuture;
        for ($i=$this->index;$i<$this->fulfill;$i++) {
            $this->promisors[$i]->promise()->when(function($e, $r) { $this->buffer .= $r; });
        }

        return $this->bufferPromisor->promise();
    }
}