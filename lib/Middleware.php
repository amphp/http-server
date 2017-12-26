<?php

namespace Aerys;

interface Middleware {
    /**
     * @param \Aerys\Request $request
     * @param \Aerys\Response $response
     *
     * @return \Aerys\Response|\Amp\Promise<\Aerys\Response>|\Generator
     */
    public function process(Request $request, Response $response);
}
