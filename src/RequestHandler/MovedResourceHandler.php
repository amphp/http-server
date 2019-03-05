<?php

namespace Amp\Http\Server\RequestHandler;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;

final class MovedResourceHandler implements RequestHandler
{
    private $path;
    private $statusCode;

    /**
     * Create a handler that redirects any requests to a new resource path.
     *
     * @param string $path New resource path.
     * @param int    $statusCode HTTP status code to set.
     *
     * @throws \Error If the given redirect URI is invalid or contains a query or fragment.
     */
    public function __construct(string $path, int $statusCode = Status::PERMANENT_REDIRECT)
    {
        if ($path[0] ?? '' !== '/') {
            throw new \Error("Path must begin with a /");
        }

        if ($statusCode < 300 || $statusCode > 399) {
            throw new \Error("Invalid status code; code in the range 300..399 required");
        }

        $this->path = $path;
        $this->statusCode = $statusCode;
    }

    public function handleRequest(Request $request): Promise
    {
        $uri = $request->getUri()->withPath($this->path);

        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($query !== "") {
            $path .= "?" . $query;
        }

        return new Success(new Response($this->statusCode, [
            "location" => $path,
            "content-length" => 0,
        ], new InMemoryStream));
    }
}
