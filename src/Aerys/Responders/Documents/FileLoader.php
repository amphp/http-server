<?php

namespace Aerys\Responders\Documents;

interface FileLoader {
    function getContents($path, callable $onComplete);
    function getHandle($path, callable $onComplete);
    function getMemoryMap($path, callable $onComplete);
}
