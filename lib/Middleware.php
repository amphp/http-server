<?php

namespace Aerys;

interface Middleware {
    /**
     * @param \Aerys\Request $request
     * @param \Aerys\Response $response
     */
    public function process(Request $request, Response $response);
}
