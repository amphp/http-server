<?php

namespace Aerys\Root;

class NaiveResponderFactory extends ResponderFactory {
    public function make(FileEntry $fileEntry, array $headerLines, array $request) {
        if (isset($request['HTTP_RANGE'])) {
            throw new \RuntimeException(
                'byte-range responses not yet implemented'
            );
        } elseif (isset($fileEntry->buffer)) {
            return new BufferResponder($fileEntry, $headerLines, $fileEntry->buffer);
        } else {
            return new NaiveStreamResponder($fileEntry, $headerLines);
        }
    }
}
