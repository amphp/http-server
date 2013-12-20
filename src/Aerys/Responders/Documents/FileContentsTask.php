<?php

namespace Aerys\Responders\Documents;

class FileContentsTask extends \Stackable {

    private $file;

    function __construct($file) {
        $this->file = $file;
    }

    function run() {
        $contents = @file_get_contents($this->file);
        $this->worker->update($contents);
    }

}
