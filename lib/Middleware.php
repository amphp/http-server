<?php

namespace Aerys;

interface Middleware {
    public function do(InternalRequest $ireq);
}
