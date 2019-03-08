<?php

namespace Amp\Http\Server\RequestHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;
use Psr\Http\Message\UriInterface as PsrUri;
use function Amp\Http\Server\redirectTo;

final class MovedResourceHandler implements RequestHandler
{
    private $path;
    private $statusCode;

    /**
     * Create a handler that redirects any requests to a new resource path.
     *
     * @param PsrUri $uri The path component of the URI is used as the new resource path.
     * @param int    $statusCode HTTP status code to set.
     *
     * @throws \Error If the given redirect URI is invalid or contains a query or fragment.
     */
    public function __construct(PsrUri $uri, int $statusCode = Status::PERMANENT_REDIRECT)
    {
        $this->path = $uri->getPath();

        if ($this->path === "") {
            throw new \Error("Empty path in provided URI");
        }

        if ($statusCode < 300 || $statusCode > 399) {
            throw new \Error("Invalid status code; code in the range 300..399 required");
        }

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

        return new Success(redirectTo($path, $this->statusCode));
    }
}
