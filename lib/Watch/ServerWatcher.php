<?php

namespace Aerys\Watch;

use Aerys\BinOptions;

interface ServerWatcher {
    public function watch(BinOptions $binOptions);
}
