<?php

namespace Aerys\Internal;

interface RequestFilter {
    public function filterRequest(Request $ireq);
}
