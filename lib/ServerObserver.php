<?php

namespace Aerys;

interface ServerObserver {
    public function update(Server $server): \Amp\Promise;
}