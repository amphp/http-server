<?php

namespace Aerys;

use Amp\ByteStream\InMemoryStream;
use Amp\Promise;
use Amp\Success;

final class NullBody extends Body {
    public function __construct() {
        parent::__construct(new InMemoryStream);
    }

    public function buffer(): Promise {
        return new Success('');
    }
}
