<?php

namespace Aerys\Responders\Documents;

class NaiveFileLoader implements FileLoader {

    function getContents($path, callable $onComplete) {
        $onComplete(@file_get_contents($path));
    }

    function getHandle($path, callable $onComplete) {
        $onComplete(@fopen($path, 'r'));
    }
    
    function getMemoryMap($path, callable $onComplete) {
        if (!$fileHandle = @fopen($path, 'r')) {
            $memoryStream = FALSE;
        } elseif (!$memoryStream = @fopen('php://memory', 'r+')) {
            $memoryStream = FALSE;
        } else {
            stream_copy_to_stream($fileHandle, $memoryStream);
            rewind($memoryStream);
            @fclose($fileHandle);
        }
        
        $onComplete($memoryStream);
    }

}
