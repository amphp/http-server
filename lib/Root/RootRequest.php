<?php

namespace Aerys\Root;

class RootRequest extends \Amp\Struct {
    public $path;
    public $request;
    public $promisor;
    public $fileEntry;
}
