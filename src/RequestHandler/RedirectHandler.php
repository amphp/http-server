<?php

namespace Amp\Http\Server\RequestHandler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Psr\Http\Message\UriInterface as PsrUri;
use function Amp\Http\Server\redirectTo;

final class RedirectHandler implements RequestHandler
{
    private string $redirectUri;
    private int $statusCode;

    /**
     * Create a redirect handler.
     *
     * @param PsrUri $uri Absolute URI prefix to redirect to. Requested URI paths and queries are appended to this URI.
     * @param int    $statusCode HTTP status code to set.
     *
     * @throws \Error If the given redirect URI is invalid or contains a query or fragment.
     */
    public function __construct(PsrUri $uri, int $statusCode = Status::TEMPORARY_REDIRECT)
    {
        if ($uri->getQuery() || $uri->getFragment()) {
            throw new \Error("Invalid redirect URI; Host redirect must not contain a query or fragment component");
        }

        if ($statusCode < 300 || $statusCode > 399) {
            throw new \Error("Invalid status code; code in the range 300..399 required");
        }

        $this->redirectUri = \rtrim((string) $uri, "/");
        $this->statusCode = $statusCode;
    }

    public function handleRequest(Request $request): Response
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($query !== "") {
            $path .= "?" . $query;
        }

        return redirectTo($this->redirectUri . $path, $this->statusCode);
    }
}
