<?php

namespace Aerys;

interface Middleware {
    public function getMiddleware(): callable;
}
