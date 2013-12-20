<?php

namespace Aerys\Responders\Documents;

use Alert\Reactor;

class FileLoaderFactory {

    private $hasPthreads;

    function __construct() {
        $this->hasPthreads = extension_loaded('pthreads');
    }

    function select(Reactor $reactor) {
        return $this->hasPthreads ? new ThreadedFileLoader($reactor) : new NaiveFileLoader;
    }

}
