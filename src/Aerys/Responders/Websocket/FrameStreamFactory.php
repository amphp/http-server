<?php

namespace Aerys\Responders\Websocket;

class FrameStreamFactory {

    function __invoke($opcode, $dataSource) {
        if (is_scalar($dataSource)) {
            $frameStream = new FrameStreamString($opcode, $dataSource);
        } elseif (is_resource($dataSource) && stream_get_meta_data($dataSource)['seekable']) {
            $frameStream = new FrameStreamResource($opcode, $dataSource);
        } else {
            throw new \InvalidArgumentException(
                'A FrameStream may only be generated from scalars or seekable resources'
            );
        }

        return $frameStream;
    }

}
