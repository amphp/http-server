<?php

namespace Aerys\Handlers\Websocket;

class FrameStreamString extends FrameStreamResource {
    
    protected function setDataSource($dataSource) {
        // We don't bother to validate here as the FrameStreamFactory already validates in practice
        // $this->validateScalar($dataSource);
        
        $uri = 'data://text/plain;base64,' . base64_encode($dataSource);
        $resource = fopen($uri, 'r');
        parent::setDataSource($resource);
    }
    
    /**
     * @codeCoverageIgnore
     */
    private function validateScalar($dataSource) {
        if (!is_scalar($dataSource)) {
            throw new \InvalidArgumentException(
                'Seekable resource required at '.__CLASS__.'::'.__METHOD__.' Argument 1'
            );
        }
    }
    
}
