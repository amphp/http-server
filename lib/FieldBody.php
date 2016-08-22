<?php declare(strict_types = 1);

namespace Aerys;

use Amp\Observable;
use Interop\Async\Awaitable;

class FieldBody extends Body {
    private $metadata;

    public function __construct(Observable $observable, Awaitable $metadata) {
        parent::__construct($observable);
        $this->metadata = $metadata;
    }
    
    public function getMetadata(): Awaitable {
        return $this->metadata;
    }
}