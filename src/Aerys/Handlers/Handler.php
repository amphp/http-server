<?php

namespace Aerys\Handlers;

interface Handler {
    function __invoke(array $asgiEnv, $requestId);
}

