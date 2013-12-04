<?php

namespace Aerys\Writing;

class ByteRangeBody {

    private $resource;
    private $startPos;
    private $endPos;

    function __construct($resource, $startPos, $endPos) {
        $this->resource = $resource;
        $this->startPos = $startPos;
        $this->endPos = $endPos;
    }

    function getResource() {
        return $this->resource;
    }

    function getStartPosition() {
        return $this->startPos;
    }

    function getEndPosition() {
        return $this->endPos;
    }

}
