<?php

namespace Aerys;

class GeneratorWriterChunked extends GeneratorWriter {
    protected function bufferBodyData($data) {
        $data = dechex(strlen($data)) . "\r\n{$data}\r\n";
        parent::bufferBodyData($data);
    }
}
