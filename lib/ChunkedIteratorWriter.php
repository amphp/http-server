<?php

namespace Aerys;

class ChunkedIteratorWriter extends IteratorWriter {
    protected function bufferBodyData($data) {
        $data = dechex(strlen($data)) . "\r\n{$data}\r\n";
        parent::bufferBodyData($data);
    }
}
