<?php

namespace Aerys;

use Amp\ByteStream\InputStream;
use Amp\Promise;

interface Body extends InputStream {
    /**
     * Buffers the entire body and resolves the returned promise then.
     *
     * @return Promise<string> Resolves with the entire body contents.
     */
    public function buffer(): Promise;
}
