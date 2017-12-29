<?php

namespace Aerys;

use Amp\Promise;

interface Responder {
    /**
     * @param \Aerys\Request $request
     *
     * @return \Amp\Promise<\Aerys\Response>
     */
    public function respond(Request $request): Promise;
}
