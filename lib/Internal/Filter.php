<?php

namespace Aerys\Internal;

interface Filter {
    /**
     * @param \Aerys\Internal\Request $request
     * @param \Aerys\Internal\Response $response
     */
    public function filter(Request $request, Response $response);
}