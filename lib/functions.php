<?php

namespace Aerys;

use Amp\Http\Status;
use League\Uri;

/**
 * Create a redirect responder.
 *
 * @param string $uri Absolute URI prefix to redirect to. Requested URI paths and queries are appended to
 *     this URI.
 * @param int $redirectCode HTTP status code to set
 *
 * @return \Aerys\Responder
 *
 * @throws \Error If the given redirect URI is invalid or contains a query or fragment.
 */
function redirect(string $uri, int $redirectCode = Status::TEMPORARY_REDIRECT): Responder {
    try {
        $uri = Uri\Http::createFromString($uri);
    } catch (Uri\Exception $exception) {
        throw new \Error($exception->getMessage());
    }

    if ($uri->getQuery() || $uri->getFragment()) {
        throw new \Error("Invalid redirect URI; Host redirect must not contain a query or fragment component");
    }

    if ($redirectCode < 300 || $redirectCode > 399) {
        throw new \Error("Invalid redirect code; code in the range 300..399 required");
    }

    $redirectUri = rtrim((string) $uri, "/");

    return new CallableResponder(function (Request $request) use ($redirectUri, $redirectCode): Response {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($query) {
            $path .= "?" . $query;
        }

        return new Response\RedirectResponse($redirectUri . $path, $redirectCode);
    });
}

/**
 * Try parsing a the Request's body with either x-www-form-urlencoded or multipart/form-data.
 *
 * @param Request $request
 * @param int $size Optional max body size.
 *
 * @return BodyParser (returns a ParsedBody instance when yielded)
 */
function parseBody(Request $request, int $size = BodyParser::DEFAULT_MAX_BODY_SIZE): BodyParser {
    return new BodyParser($request, $size);
}
