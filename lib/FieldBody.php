<?php

namespace Aerys;

use Amp\ByteStream\InputStream;
use Amp\Promise;

class FieldBody extends DefaultBody {
    private $metadata;

    public function __construct(InputStream $stream, Promise $metadata) {
        parent::__construct($stream);
        $this->metadata = $metadata;
    }

    public function getMetadata(): Promise {
        return $this->metadata;
    }
}
