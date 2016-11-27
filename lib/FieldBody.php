<?php declare(strict_types = 1);

namespace Aerys;

use Amp\Observable;
use Interop\Async\Promise;

class FieldBody extends Body {
    private $metadata;

    public function __construct(Observable $observable, Promise $metadata) {
        parent::__construct($observable);
        $this->metadata = $metadata;
    }
    
    public function getMetadata(): Promise {
        return $this->metadata;
    }
}