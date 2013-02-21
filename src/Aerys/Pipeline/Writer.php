<?php

namespace Aerys\Pipeline;

interface Writer {
    function write();
    function enqueue($response);
}

