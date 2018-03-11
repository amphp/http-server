<?php

namespace Amp\Http\Server;

use Amp\Promise;

interface Responder {
    /**
     * @param \Amp\Http\Server\Request $request
     *
     * @return \Amp\Promise<\Amp\Http\Server\Response>
     */
    public function respond(Request $request): Promise;
}
