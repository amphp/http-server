<?php

namespace Amp\Http\Server\RequestHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final class ClosureRequestHandler implements RequestHandler
{
    /**
     * @param \Closure(Request):Response $closure Closure accepting an {@see Request} object as the first
     * argument and returning an instance of {@see Response}.
     */
    public function __construct(private \Closure $closure)
    {
    }

    public function handleRequest(Request $request): Response
    {
        return ($this->closure)($request);
    }
}
