<?php

namespace Aerys;

class AsgiResponderFactory {
    /**
     * Generate a responder from a standard ASGI response map
     *
     * We specifically avoid typehinting the $asgiResponseMap parameter as an array here to allow
     * for ArrayAccess instances.
     *
     * @param array|ArrayAccess $asgiResponseMap
     * @return AsgiMapResponder
     */
    public function make($asgiResponseMap) {
        return new AsgiMapResponder($asgiResponseMap);
    }
}
