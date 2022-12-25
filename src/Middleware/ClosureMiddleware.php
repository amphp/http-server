<?php declare(strict_types=1);

namespace Amp\Http\Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final class ClosureMiddleware implements Middleware
{
    /**
     * @param \Closure(Request, RequestHandler):Response $closure Closure accepting an {@see Request} object
     * as the first argument, {@see RequestHandler} as second argument and returning an instance of {@see Response}.
     */
    public function __construct(private readonly \Closure $closure)
    {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        return ($this->closure)($request, $requestHandler);
    }
}
