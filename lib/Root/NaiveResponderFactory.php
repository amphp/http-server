<?php

namespace Aerys\Root;

class NaiveResponderFactory extends ResponderFactory {
    public function make(FileEntry $fileEntry, array $headerLines, array $request, Range $range = null) {
        $isBuffered = isset($fileEntry->buffer);

        if ($range) {
            return $isBuffered
                ? new BufferRangeResponder($fileEntry, $headerLines, $range)
                : new NaiveStreamRangeResponder($fileEntry, $headerLines, $range);
        } elseif ($isBuffered) {
            return new BufferResponder($fileEntry, $headerLines);
        } else {
            return new NaiveStreamResponder($fileEntry, $headerLines);
        }
    }
}
