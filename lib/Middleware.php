<?php declare(strict_types = 1);

namespace Aerys;

interface Middleware {
    public function do(InternalRequest $ireq);
}
