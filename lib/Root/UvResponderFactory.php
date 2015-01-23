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
            // We have to run data through userland so our stream
            // wrapper can encrypt it.
            return new UvStreamResponder($fileEntry, $headerLines);
        } else {
            // If the transfer doesn't require encryption we can
            // avoid bringing the data into userland altogether and
            // use sendfile().
            return new UvSendfileResponder($fileEntry, $headerLines);
        }
    }
}
