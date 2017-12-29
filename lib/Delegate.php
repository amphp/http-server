<?php

namespace Aerys;

use Amp\Promise;

interface Delegate {
    /**
     * @param \Aerys\Request $request
     *
     * @return \Amp\Promise<\Aerys\Response|null>
     */
    public function delegate(Request $request): Promise;
}
