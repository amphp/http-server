<?php

namespace Aerys\Root;

class UvResponderFactory extends ResponderFactory {
    public function make(FileEntry $fileEntry, array $headerLines, array $request, Range $range = null) {
        $isBuffered = isset($fileEntry->buffer);

        if ($range) {
            return $isBuffered
                ? new BufferRangeResponder($fileEntry, $headerLines, $range)
                : new UvStreamRangeResponder($fileEntry, $headerLines, $range);
        } elseif ($isBuffered) {
            return new BufferResponder($fileEntry, $headerLines);
        } elseif ($request['HTTPS']) {
            return new UvStreamResponder($fileEntry, $headerLines);
        } else {
            return new UvSendFileResponder($fileEntry, $headerLines);
        }
    }
}
