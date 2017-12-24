<?php

namespace Aerys;

use Amp\Promise;
use Amp\Success;

final class NullBody implements Body {
    public function read(): Promise {
        return new Success;
    }

    public function buffer(): Promise {
        return new Success('');
    }
}
