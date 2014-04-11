<?php

namespace Aerys\Watch;

use Aerys\Start\BinOptions;

interface ServerWatcher {
    public function watch(BinOptions $binOptions);
}
