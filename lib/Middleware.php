<?php

namespace Aerys;

interface Middleware {
    public function use(InternalRequest $ireq);
}
