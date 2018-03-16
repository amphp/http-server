<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Server\Driver\Client;
use Psr\Http\Message\UriInterface as PsrUri;

class Request extends Message {
    /** @var Client */
    private $client;

    /** @var string */
    private $method;

    /** @var PsrUri */
    private $uri;

    /** @var string */
    private $protocol;

    /** @var \Amp\Http\Server\Body|null */
    private $body;

    /** @var RequestCookie[] */
    private $cookies = [];

    /** @var mixed[] */
    private $attributes = [];

    /**
     * @param Client $client The client sending the request.
     * @param string $method HTTP request method.
     * @param PsrUri $uri The full URI being requested, including host, port, and protocol.
     * @param string[]|string[][] $headers An array of strings or an array of string arrays.
     * @param Body|InputStream|string|null $body
     * @param string $protocol HTTP protocol version (e.g. 1.0, 1.1, or 2.0).
     */
    public function __construct(
        Client $client,
        string $method,
        PsrUri $uri,
        array $headers = [],
        $body = null,
        string $protocol = "1.1"
    ) {
        $this->client = $client;
        $this->method = $method;
        $this->uri = $uri;
        $this->protocol = $protocol;

        if (!empty($headers)) {
            $this->setHeaders($headers);
        }

        if ($body !== null) {
            $this->setBody($body);
        }
    }

    /**
     * @return \Amp\Http\Server\Driver\Client The client sending the request.
     */
    public function getClient(): Client {
        return $this->client;
    }

    /**
     * Sets the request HTTP method.
     *
     * @param string $method
     */
    public function setMethod(string $method) {
        $this->method = $method;
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
     * @return PsrUri
     */
    public function getUri(): PsrUri {
        return $this->uri;
    }

    /**
     * Sets a new URI for the request.
     *
     * @param PsrUri $uri
     */
    public function setUri(PsrUri $uri) {
        $this->uri = $uri;
    }

    /**
     * This method returns the HTTP protocol version (e.g. "1.0", "1.1", "2.0") in use;
     * it has nothing to do with URI schemes like http:// or https:// ...
     *
     * @return string
     */
    public function getProtocolVersion(): string {
        return $this->protocol;
    }

    /**
     * Sets a new protocol version number for the request.
     *
     * @param string $protocol
     */
    public function setProtocolVersion(string $protocol) {
        $this->protocol = $protocol;
    }

    /**
     * Sets the named header to the given value.
     *
     * @param string $name
     * @param string|string[] $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    public function setHeader(string $name, $value) {
        parent::setHeader($name, $value);

        if (\stripos($name, "cookie") === 0) {
            $this->setCookiesFromHeaders();
        }
    }

    /**
     * Adds the value to the named header, or creates the header with the given value if it did not exist.
     *
     * @param string $name
     * @param string|string[] $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    public function addHeader(string $name, $value) {
        parent::addHeader($name, $value);

        if (\stripos($name, "cookie") === 0) {
            $this->setCookiesFromHeaders();
        }
    }

    /**
     * Removes the given header if it exists.
     *
     * @param string $name
     */
    public function removeHeader(string $name) {
        parent::removeHeader($name);

        if (\stripos($name, "cookie") === 0) {
            $this->cookies = [];
        }
    }

    /**
     * Retrieve the request body.
     *
     * @return \Amp\Http\Server\Body
     */
    public function getBody(): Body {
        if ($this->body === null) {
            $this->body = new Body(new InMemoryStream);
        }

        return $this->body;
    }

    /**
     * Sets the stream for the message body. Note that using a string will automatically set the Content-Length header
     * to the length of the given string. Using an InputStream or Body instance will remove the Content-Length header.
     *
     * @param Body|InputStream|string|null $stringOrStream
     *
     * @throws \Error
     * @throws \TypeError
     */
    public function setBody($stringOrStream) {
        if ($stringOrStream instanceof Body) {
            $this->body = $stringOrStream;
            return;
        }

        if ($stringOrStream instanceof InputStream) {
            $this->body = new Body($stringOrStream);
            return;
        }

        if ($stringOrStream !== null && !\is_string($stringOrStream)) {
            throw new \TypeError(\sprintf(
                "The request body must a string, null, or an instance of %s or %s ",
                Body::class,
                InputStream::class
            ));
        }

        $this->body = new Body(new InMemoryStream($stringOrStream));
        if ($length = \strlen($stringOrStream)) {
            $this->setHeader("content-length", (string) \strlen($stringOrStream));
        } elseif (!\in_array($this->method, ["GET", "HEAD", "OPTIONS", "TRACE"])) {
            $this->setHeader("content-length", "0");
        } else {
            $this->removeHeader("content-length");
        }
    }

    /**
     * @return RequestCookie[]
     */
    public function getCookies(): array {
        return $this->cookies;
    }

    /**
     * @param string $name Name of the cookie.
     *
     * @return RequestCookie|null
     */
    public function getCookie(string $name) { /* : ?RequestCookie */
        return $this->cookies[$name] ?? null;
    }

    /**
     * Adds a cookie to the response.
     *
     * @param RequestCookie $cookie
     */
    public function setCookie(RequestCookie $cookie) {
        $this->cookies[$cookie->getName()] = $cookie;
        $this->setHeadersFromCookies();
    }

    /**
     * Removes a cookie from the response.
     *
     * @param string $name
     */
    public function removeCookie(string $name) {
        if (isset($this->cookies[$name])) {
            unset($this->cookies[$name]);
            $this->setHeadersFromCookies();
        }
    }

    /**
     * Sets cookies based on headers.
     *
     * @throws \Error
     */
    private function setCookiesFromHeaders() {
        $this->cookies = [];

        $headers = $this->getHeaderArray("cookie");

        foreach ($headers as $line) {
            $cookies = RequestCookie::fromHeader($line);
            foreach ($cookies as $cookie) {
                $this->cookies[$cookie->getName()] = $cookie;
            }
        }
    }

    /**
     * Sets headers based on cookie values.
     */
    private function setHeadersFromCookies() {
        $values = [];

        foreach ($this->cookies as $cookie) {
            $values[] = (string) $cookie;
        }

        $this->setHeader("cookie", $values);
    }

    /**
     * Retrieve a variable from the request's mutable local storage.
     *
     * Each request has its own mutable local storage to which responders and middleware may read and write data. Other
     * responders or middleware which are aware of this data can then access it without the server being tightly coupled
     * to specific implementations.
     *
     * @param string $name Name of the attribute, should be namespaced with a vendor and package namespace like classes.
     *
     * @return mixed
     */
    public function getAttribute(string $name) {
        if (!isset($this->attributes[$name])) {
            throw new MissingAttributeError("The requested attribute '{$name}' does not exist");
        }

        return $this->attributes[$name];
    }

    /**
     * Assign a variable to the request's mutable local storage.
     *
     * Each request has its own mutable local storage to which responders and middleware may read and write data. Other
     * responders or middleware which are aware of this data can then access it without the server being tightly coupled
     * to specific implementations.
     *
     * **Example**
     *
     * ```php
     * $request->setAttribute(Router::class, $routeArguments);
     * ```
     *
     * @param string $name Name of the attribute, should be namespaced with a vendor and package namespace like classes.
     * @param mixed $value Value of the attribute, might be any value.
     */
    public function setAttribute(string $name, $value) {
        $this->attributes[$name] = $value;
    }
}
