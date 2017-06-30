<?php

namespace Aerys;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\Message;

final class NullBody extends Message {
    public function __construct() {
        parent::__construct(new InMemoryStream);
    }
}
