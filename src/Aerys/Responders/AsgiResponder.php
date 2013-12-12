<?php

namespace Aerys\Responders;

interface AsgiResponder {
    function __invoke(Request $request);
}
