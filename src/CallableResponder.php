<?php

namespace Amp\Http\Server;

use Amp\Promise;
use function Amp\call;

class CallableResponder implements Responder {
    /** @var callable */
    private $callable;

    /**
     * @param callable $callable Callable accepting an \Aerys\Request object as the first argument and returning an
     *     instance of \Aerys\Response. If the callable returns a generator, it will be run as a coroutine.
     */
    public function __construct(callable $callable) {
        $this->callable = $callable;
    }

    /**
     * {@inheritdoc}
     */
    public function respond(Request $request): Promise {
        return call($this->callable, $request);
    }
}
