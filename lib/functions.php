<?php

namespace Aerys;

use Aerys\Cookie\Cookie;

/**
 * Create a redirect responder for use in a Host instance.
 *
 * @param string $absoluteUri Absolute URI prefix to redirect to
 * @param int $redirectCode HTTP status code to set
 * @return callable Responder callable
 */
function redirect(string $absoluteUri, int $redirectCode = 307): Responder {
    if (!$url = @parse_url($absoluteUri)) {
        throw new \Error("Invalid redirect URI");
    }
    if (empty($url["scheme"]) || ($url["scheme"] !== "http" && $url["scheme"] !== "https")) {
        throw new \Error("Invalid redirect URI; \"http\" or \"https\" scheme required");
    }
    if (isset($url["query"]) || isset($url["fragment"])) {
        throw new \Error("Invalid redirect URI; Host redirect must not contain a query or fragment component");
    }

    $absoluteUri = rtrim($absoluteUri, "/");

    if ($redirectCode < 300 || $redirectCode > 399) {
        throw new \Error("Invalid redirect code; code in the range 300..399 required");
    }

    return new CallableResponder(function (Request $req) use ($absoluteUri, $redirectCode) {
        $uri = $req->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($query) {
            $path .= "?" . $query;
        }

        return new Response\RedirectResponse($absoluteUri . $path, $redirectCode);
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

/**
 * Parses cookies into an array.
 *
 * @param string $cookies
 *
 * @return \Aerys\Cookie\Cookie[]
 */
function parseCookie(string $cookies): array {
    $result = [];

    foreach (\explode("; ", $cookies) as $cookie) {
        $cookie = Cookie::fromHeader($cookie);
        $result[$cookie->getName()] = $cookie;
    }

    return $result;
}
