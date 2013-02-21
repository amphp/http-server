<?php

namespace Aerys\Http;

interface Handler {
    function __invoke(array $asgiEnv, $requestId);
}

