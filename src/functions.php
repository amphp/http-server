<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Status;
use Psr\Http\Message\UriInterface as PsrUri;

/**
 * Create a redirect handler.
 *
 * @param PsrUri $uri Absolute URI prefix to redirect to. Requested URI paths and queries are appended to this URI.
 * @param int    $redirectCode HTTP status code to set.
 *
 * @return RequestHandler
 *
 * @throws \Error If the given redirect URI is invalid or contains a query or fragment.
 */
function redirect(PsrUri $uri, int $redirectCode = Status::TEMPORARY_REDIRECT): RequestHandler {
    if ($uri->getQuery() || $uri->getFragment()) {
        throw new \Error("Invalid redirect URI; Host redirect must not contain a query or fragment component");
    }

    if ($redirectCode < 300 || $redirectCode > 399) {
        throw new \Error("Invalid redirect code; code in the range 300..399 required");
    }

    $redirectUri = rtrim((string) $uri, "/");

    return new CallableRequestHandler(function (Request $request) use ($redirectUri, $redirectCode): Response {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($query !== "") {
            $path .= "?" . $query;
        }

        return new Response($redirectCode, [
            "location" => $redirectUri . $path,
            "content-length" => 0,
        ], new InMemoryStream);
    });
}
