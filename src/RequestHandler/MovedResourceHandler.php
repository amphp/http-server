<?php

namespace Amp\Http\Server\RequestHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use function Amp\Http\Server\redirectTo;

final class MovedResourceHandler implements RequestHandler
{
    /**
     * Create a handler that redirects any requests to a new resource path.
     *
     * @param string $path New path of the resource.
     * @param int $statusCode HTTP status code to set.
     *
     * @throws \Error If the given redirect URI is invalid or contains a query or fragment.
     */
    public function __construct(
        private readonly string $path,
        private readonly int $statusCode = Status::PERMANENT_REDIRECT)
    {
        if ($this->path === "") {
            throw new \Error("Empty path in provided URI");
        }

        if ($statusCode < 300 || $statusCode > 399) {
            throw new \Error("Invalid status code; code in the range 300..399 required");
        }
    }

    public function handleRequest(Request $request): Response
    {
        $uri = $request->getUri()->withPath($this->path);

        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($query !== "") {
            $path .= "?" . $query;
        }

        return redirectTo($path, $this->statusCode);
    }
}
