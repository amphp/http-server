<?php

namespace Aerys;

use Amp\Emitter;

final class NullBody extends Body {
    public function __construct() {
        $emitter = new Emitter;
        $emitter->complete();
        parent::__construct($emitter->iterate());
    }
}
