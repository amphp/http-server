<?php

namespace Aerys\Root;

class UvStreamRangeResponder extends StreamRangeResponder {
    private $uvLoop;

    public function __construct(UvFileEntry $fileEntry, array $headerLines, Range $range) {
        $this->uvLoop = $fileEntry->uvLoop;
        parent::__construct($fileEntry, $headerLines, $range);
    }

    final protected function bufferFileChunk($handle, $offset, $length, callable $onComplete) {
        $onRead = function($handle, $nread, $buffer) use ($onComplete) {
            if ($nread < 0) {
                $buffer = false;
            }
            $onComplete($buffer);
        };
        uv_fs_read($this->uvLoop, $handle, $offset, $length, $onRead);
    }
}
