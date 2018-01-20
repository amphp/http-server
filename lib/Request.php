<?php

namespace Aerys;

use Aerys\Cookie\Cookie;
use Amp\Uri\Uri;

class Request {
    /** @var string */
    private $method;

    /** @var \Amp\Uri\Uri */
    private $uri;

    /** @var string */
    private $target;

    /** @var string */
    private $protocol;

    /** @var string[][] */
    private $headers = [];

    /** @var \Aerys\Body|null */
    private $body;

    /** @var \Aerys\Cookie\Cookie[] */
    private $cookies = [];

    /** @var mixed[] */
    private $attributes = [];

    /**
     * @param string $method HTTP request method.
     * @param Uri $uri The full URI being requested, including host, port, and protocol.
     * @param string[]|string[][] $headers An array of strings or an array of string arrays.
     * @param Body|null $body
     * @param string|null $target Request target. Usually similar to URI, but contains only the exact string provided
     *    in the request line.
     * @param string $protocol HTTP protocol version (e.g. 1.0, 1.1, or 2.0).
     */
    public function __construct(
        string $method,
        Uri $uri,
        array $headers = [],
        Body $body = null,
        string $target = null,
        string $protocol = "1.1"
    ) {
        $this->method = $method;
        $this->uri = $uri;
        $this->protocol = $protocol;
        $this->body = $body ?? new NullBody;
        $this->target = $target ?? ($uri->getPath() . ($uri->getQuery() ? "?" . $uri->getQuery() : ""));

        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                $value = \array_map("strval", $value);
            } else {
                $value = [(string) $value];
            }

            $name = \strtolower($name);
            $this->headers[$name] = $value;
        }

        if (!empty($this->headers["cookie"])) { // @TODO delay initialization
            $cookies = \array_filter(\array_map([Cookie::class, "fromHeader"], $this->headers["cookie"]));
            /** @var Cookie $cookie */
            foreach ($cookies as $cookie) {
                $this->cookies[$cookie->getName()] = $cookie;
            }
        }
    }

    /**
     * Retrieve the HTTP method used to make this request.
     *
     * @return string
     */
    public function getMethod(): string {
        return $this->method;
    }

    /**
     * Retrieve the request URI.
     *
     * @return \Amp\Uri\Uri
     */
    public function getUri(): Uri {
        return $this->uri;
    }

    /**
     * Retrieve the request target path.
     *
     * @return string
     */
    public function getTarget(): string {
        return $this->target;
    }

    /**
     * Retrieve the HTTP protocol version number used by this request.
     *
     * This method returns the HTTP protocol version (e.g. "1.0", "1.1", "2.0") in use;
     * it has nothing to do with URI schemes like http:// or https:// ...
     *
     * @return string
     */
    public function getProtocolVersion(): string {
        return $this->protocol;
    }

    /**
     * Retrieve the first occurrence of specified header in the message.
     *
     * If multiple headers were received for the specified field only the
     * value of the first header is returned. Applications may use
     * Request::getHeaderArray() to retrieve a list of all header values
     * received for a given field.
     *
     * All header $field names are treated case-insensitively.
     *
     * A null return indicates the requested header field was not present.
     *
     * @param string $field
     * @return string|null
     */
    public function getHeader(string $field) { /* : ?string */
        return $this->headers[strtolower($field)][0] ?? null;
    }

    /**
     * Retrieve the specified header as an array of each of its occurrences in the request.
     *
     * All header $field names are treated case-insensitively.
     *
     * An empty return array indicates that the header was not present in the request.
     *
     * @param string $field
     * @return array
     */
    public function getHeaderArray(string $field): array {
        return $this->headers[strtolower($field)] ?? [];
    }

    /**
     * Retrieve an array of all headers in the message.
     *
     * The returned array uses header names normalized to all-lowercase for
     * simplified querying via isset().
     *
     * @return array
     */
    public function getHeaders(): array {
        return $this->headers;
    }

    /**
     * Retrieve the streaming request entity body.
     *
     * @return \Aerys\Body
     */
    public function getBody(): Body {
        return $this->body;
    }

    /**
     * Retrieve a cookie.
     *
     * @param string $name
     * @return \Aerys\Cookie\Cookie|null
     */
    public function getCookie(string $name) { /* : ?Cookie */
        return $this->cookies[$name] ?? null;
    }

    /**
     * Retrieve a variable from the request's mutable local storage.
     *
     * Each request has its own mutable local storage to which application
     * callables and middleware may read and write data. Other callables
     * which are aware of this data can then access it without the server
     * being tightly coupled to specific implementations.
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute(string $key) {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Assign a variable to the request's mutable local storage.
     *
     * Each request has its own mutable local storage to which application
     * callables and middleware may read and write data. Other callables
     * which are aware of this data can then access it without the server
     * being tightly coupled to specific implementations.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setAttribute(string $key, $value) {
        $this->attributes[$key] = $value;
    }
}
