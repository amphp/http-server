<?php

namespace Aerys;

use Amp\{ Message, Success };

final class NullBody extends Message {
    public function __construct() {
        parent::__construct(new Success);
    }
}
