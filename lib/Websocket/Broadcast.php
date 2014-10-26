<?php

namespace Aerys\Websocket;

final class Broadcast {
    private $data;
    private $include;
    private $exclude;

    public function __construct($data, array $include = [], array $exclude = []) {
        $this->data = $data;
        $this->include = $include;
        $this->exclude = $exclude;
    }

    public function getData() {
        return $this->data;
    }

    public function getInclude() {
        return $this->include;
    }

    public function getExclude() {
        return $this->include;
    }

    public function toArray() {
        return [$this->data, $this->include, $this->exclude];
    }
}
