<?php

namespace Aerys;

use Amp\Success;

final class NullBody extends Body {
    public function __construct() {
        parent::__construct(new Success);
    }
}
