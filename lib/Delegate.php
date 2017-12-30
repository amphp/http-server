<?php

namespace Aerys;

use Amp\Promise;

interface Delegate {
    /**
     * Similar to the Responder interface, except delegates may choose to not return null instead of a Response object,
     * indicating a response is not available for the given request and that another delegate should be tried.
     *
     * @param \Aerys\Request $request
     *
     * @return \Amp\Promise<\Aerys\Response|null>
     */
    public function delegate(Request $request): Promise;
}
