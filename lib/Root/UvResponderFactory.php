<?php

namespace Aerys\Root;

class UvResponderFactory extends ResponderFactory {
    public function make(FileEntry $fileEntry, array $headerLines, array $request) {
        if (isset($request['HTTP_RANGE'])) {
            throw new \RuntimeException(
                'libuv byte-range responses not yet implemented'
            );
        } elseif (isset($fileEntry->buffer)) {
            return new BufferResponder($fileEntry, $headerLines, $fileEntry->buffer);
        } elseif ($request['HTTPS']) {
            return new UvStreamResponder($fileEntry, $headerLines);
        } else {
            return new UvSendFileResponder($fileEntry, $headerLines);
        }
    }
}
