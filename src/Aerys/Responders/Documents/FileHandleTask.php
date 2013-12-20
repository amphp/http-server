<?php

namespace Aerys\Responders\Documents;

class FileHandleTask extends \Stackable {

    private $file;

    function __construct($file) {
        $this->file = $file;
    }

    function run() {
        $fh = @fopen($this->file, 'r');
        $this->worker->update($fh);
    }

}
