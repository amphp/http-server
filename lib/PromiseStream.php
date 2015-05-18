<?php

namespace Aerys;

use Amp\{
    Promise,
    PrivateFuture
};

class PromiseStream {
    const NONE      = 0b000;
    const BUFFERING = 0b001;
    const STREAMING = 0b010;
    const ERROR     = 0b100;

    private $promisors;
    private $bufferPromisor;
    private $buffer = "";
    private $index = 0;
    private $state = self::NONE;

    public function __construct() {
        $this->promisors[] = new PrivateFuture;
    }

    public function fail(\Exception $e) {
        $this->state = self::ERROR;
        if ($this->state & self::BUFFERING) {
            $this->buffer = null;
            $this->bufferPromisor->fail($e);
        } else {
            current($this->promisors)->fail($e);
        }
    }

    public function end(string $data = null) {
        if (isset($data)) {
            $this->sink($data);
        }
        if ($this->state & self::BUFFERING) {
            $this->bufferPromisor->succeed($this->buffer);
            $this->buffer = null;
        } else {
            $this->promisors[$this->index]->succeed();
            $this->isEndOfStream = true;
        }
    }

    public function sink(string $data) {
        if ($this->state & self::BUFFERING) {
            $this->buffer .= $data;
        } else {
            $this->promisors[$this->index + 1] = new PrivateFuture;
            $this->promisors[$this->index++]->succeed($data);
        }
    }

    public function stream(): \Generator {
        if ($this->state & self::BUFFERING) {
            throw new \LogicException(
                "Cannot stream once buffer() is invoked"
            );
        }
        while ($this->promisors) {
            $key = key($this->promisors);
            yield $this->promisors[$key]->promise();
            unset($this->promisors[$key]);
        }
    }

    public function buffer(): Promise {
        if ($this->state & self::BUFFERING) {
            return $this->bufferPromisor;
        }
        if ($this->state) {
            throw new \LogicException(sprintf(
                "Cannot buffer(); promise stream already in %s state",
                ($this->state & ERROR) ? "ERROR" : "STREAMING"
            ));
        }

        $this->isBuffering = true;
        $this->bufferPromisor = new PrivateFuture;
        for ($i=0;$i<$this->index;$i++) {
            $this->promisors[$i]->promise()->when(function($e, $r) {
                if ($e) {
                    throw $e;
                }
                $this->buffer .= $r;
            });
        }

        return $this->bufferPromisor->promise();
    }
}
