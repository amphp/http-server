<?php declare(strict_types = 1);

namespace Aerys;

interface ServerObserver {
    public function update(Server $server): \Interop\Async\Promise;
}