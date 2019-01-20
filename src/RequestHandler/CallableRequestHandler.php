<?php

namespace Amp\Http\Server\RequestHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Promise;
use function Amp\call;

final class CallableRequestHandler implements RequestHandler
{
    /** @var callable */
    private $callable;

    /**
     * @param callable $callable Callable accepting an \Amp\Http\Server\Request object as the first argument and returning an
     *     instance of \Amp\Http\Server\Response. If the callable returns a generator, it will be run as a coroutine.
     */
    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    /**
     * {@inheritdoc}
     */
    public function handleRequest(Request $request): Promise
    {
        return call($this->callable, $request);
    }
}
