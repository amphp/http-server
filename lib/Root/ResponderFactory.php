<?php

namespace Aerys\Root;

abstract class ResponderFactory {
    abstract public function make(FileEntry $fileEntry, array $headerLines, array $request);
}
