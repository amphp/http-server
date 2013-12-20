<?php

namespace Aerys\Responders\Documents;

class FileMemoryMapTask extends \Stackable {

    private $file;

    function __construct($file) {
        $this->file = $file;
    }

    function run() {
        if (!$fileHandle = @fopen($this->file, 'r')) {
            $memoryStream = FALSE;
        } elseif (!$memoryStream = @fopen('php://memory', 'r+')) {
            $memoryStream = FALSE;
        } else {
            stream_copy_to_stream($fileHandle, $memoryStream);
            rewind($memoryStream);
            @fclose($fileHandle);
        }

        $this->worker->update($memoryStream);
    }

}
