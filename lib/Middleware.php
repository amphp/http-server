<?php

namespace Aerys;

interface Middleware {
    public function filter(): callable;
}
