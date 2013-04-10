<?php

namespace Aerys\Writing;

interface BodyWriter {
    
    function write();
    function setGranularity($bytes);
    
}

