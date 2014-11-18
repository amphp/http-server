<?php

namespace Aerys\Root;

use Aerys\ResponderStruct;

final class NaiveStreamRangeResponder extends StreamRangeResponder {
    private $reactor;

    public function prepare(ResponderStruct $responderStruct) {
        $this->reactor = $responderStruct->reactor;
        parent::prepare($responderStruct);
    }

    final protected function bufferFileChunk($handle, $offset, $length, callable $onComplete) {
        @fseek($handle, $offset);
        $buffer = @fread($handle, $length);
        $this->reactor->immediately(function() use ($buffer, $onComplete) {
            $onComplete($buffer);
        });
    }
}
