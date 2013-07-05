<?php

namespace Aerys\Handlers\Websocket;

class FrameStreamFactory {
    
    function __invoke($opcode, $dataSource) {
        if ($dataSource instanceof FrameStream) {
            $frameStream = $dataSource;
        } elseif (is_scalar($dataSource)) {
            $frameStream = new FrameStreamString($opcode, $dataSource);
        } elseif (is_resource($dataSource) && stream_get_meta_data($dataSource)['seekable']) {
            $frameStream = new FrameStreamResource($opcode, $dataSource);
        } elseif ($dataSource instanceof \SeekableIterator) {
            $frameStream = new FrameStreamSequence($opcode, $dataSource);
        } else {
            throw new \InvalidArgumentException(
                'A FrameStream may only be generated from scalars, seekable resources or ' .
                'SeekableIterator instances'
            );
        }
        
        return $frameStream;
    }
    
}

