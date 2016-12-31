<?php

namespace Aerys;

final class NullBody extends Body {
    public function __construct() {
        parent::__construct(new \Amp\Success);
    }
}
