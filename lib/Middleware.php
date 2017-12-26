<?php

namespace Aerys;

interface Middleware {
    /**
     * @param \Aerys\Request $request
     * @param callable $next Next request handler.
     *
     * @return \Aerys\Response|\Amp\Promise<\Aerys\Response>|\Generator
     */
    public function process(Request $request, callable $next);
}
