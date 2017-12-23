<?php

namespace Aerys\Internal;

interface ResponseFilter {
    public function filterResponse(Request $ireq, Response $ires);
}
