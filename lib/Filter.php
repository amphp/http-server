<?php

namespace Aerys;

interface Filter {
    public function filter(InternalRequest $ireq);
}
