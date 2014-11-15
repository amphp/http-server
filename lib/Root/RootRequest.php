<?php

namespace Aerys\Root;

use Aerys\Struct;

class RootRequest extends Struct {
    public $path;
    public $request;
    public $promisor;
    public $fileEntry;
}
