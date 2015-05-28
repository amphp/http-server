<?php

namespace Aerys;

interface Middleware {
    public function filter(InternalRequest $ireq);
}
