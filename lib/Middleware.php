<?php

namespace Aerys;

use Amp\Promise;

interface Middleware {
    /**
     * @param \Aerys\Request $request
     * @param \Aerys\Responder $responder Request responder.
     *
     * @return \Amp\Promise<\Aerys\Response>
     */
    public function process(Request $request, Responder $responder): Promise;
}
