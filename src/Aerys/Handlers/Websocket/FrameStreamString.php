<?php

namespace Aerys\Handlers\Websocket;

class FrameStreamString extends FrameStreamResource {
    
    protected function setDataSource($dataSource) {
        $uri = 'data://text/plain;base64,' . base64_encode($dataSource);
        $resource = fopen($uri, 'r');
        parent::setDataSource($resource);
    }
    
}
