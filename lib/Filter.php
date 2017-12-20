<?php

namespace Aerys;

interface Filter {
    public function do(InternalRequest $ireq);
}
