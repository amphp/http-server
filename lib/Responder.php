<?php

namespace Aerys;

interface Responder {
    /**
     * @param \Aerys\Request $request
     *
     * @return \Aerys\Response|\Amp\Promise<\Aerys\Response>|\Generator|null
     */
    public function __invoke(Request $request);
}