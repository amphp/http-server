<?php

namespace Aerys\Responders;

interface AsgiResponder {
    function __invoke(array $asgiEnv, $requestId);
}
