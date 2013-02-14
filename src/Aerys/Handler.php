<?php

namespace Aerys;

interface Handler {
    function __invoke(array $asgiEnv, $requestId);
}

