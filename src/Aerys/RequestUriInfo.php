<?php

namespace Aerys;

/**
 * Why do we need this? For speed. The native parse_url() function is slow (not because it is poorly
 * written, but because URL parsing is pretty complicated). To minimize parse_url() calls during the
 * course of request handling we store the URI info we need for each request here and associate the
 * instance with the relevant request.
 */
class RequestUriInfo {

    private $isAbsolute;
    private $scheme;
    private $host;
    private $port;
    private $path;
    private $query;
    private $raw;

    function __construct($requestUri) {
        $this->raw = $requestUri;
        if (stripos($requestUri, 'http://') === 0 || stripos($requestUri, 'https://') === 0) {
            $parts = parse_url($requestUri);
            $this->isAbsolute = TRUE;
            $this->scheme = $parts['scheme'];
            $this->host = $parts['host'];
            $this->port = $parts['port'];
            $this->path = $parts['path'];
            $this->query = $parts['query'];
        } elseif ($qPos = strpos($requestUri, '?')) {
            $this->query = substr($requestUri, $qPos + 1);
            $this->path = substr($requestUri, 0, $qPos);
        } else {
            $this->path = $requestUri;
        }
    }

    function isAbsolute() {
        return (bool) $this->isAbsolute;
    }

    function getScheme() {
        return $this->scheme;
    }

    function getHost() {
        return $this->host;
    }

    function getPort() {
        return $this->port;
    }

    function getPath() {
        return $this->path;
    }

    function getQuery() {
        return $this->query;
    }

    function getRaw() {
        return $this->raw;
    }

}
