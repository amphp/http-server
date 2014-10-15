<?php

use Amp\Success;
use Amp\Failure;

// In reality we'd use non-blocking libraries here to return promises.
// For the purpose of this example we'll just return resolved promises
// whose values are already fulfilled.

function asyncMultiply($x, $y) {
    return new Success($x*$y);
}

function asyncSubtract($x, $y) {
    return new Success($x-$y);
}

function asyncFailure() {
    return new Failure(new \RuntimeException(
        'Example async failure'
    ));
}
