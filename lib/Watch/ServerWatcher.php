<?php

namespace Aerys\Watch;

interface ServerWatcher {
    public function watch(BinOptions $binOptions);
}
