<?php

namespace Aerys\Responders;

interface AsgiResponder {
    function __invoke($request);
}
