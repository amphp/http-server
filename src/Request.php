<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Message;
use Amp\Http\Server\Driver\Client;
use Psr\Http\Message\UriInterface as PsrUri;

final class Request extends Message
{
    /** @var Client */
    private $client;

    /** @var string */
    private $method;

    /** @var PsrUri */
    private $uri;

    /** @var string */
    private $protocol;

    /** @var RequestBody|null */
    private $body;

    /** @var RequestCookie[] */
    private $cookies = [];

    /** @var mixed[] */
    private $attributes = [];

    /** @var Trailers|null */
    private $trailers;

    /**
     * @param Client                              $client The client sending the request.
     * @param string                              $method HTTP request method.
     * @param PsrUri                              $uri The full URI being requested, including host, port, and protocol.
     * @param string[]|string[][]                 $headers An array of strings or an array of string arrays.
     * @param RequestBody|InputStream|string|null $body
     * @param string                              $protocol HTTP protocol version (e.g. 1.0, 1.1, or 2.0).
     * @param Trailers|null                       $trailers Trailers if request has trailers, or null otherwise.
     */
    public function __construct(
        Client $client,
        string $method,
        PsrUri $uri,
        array $headers = [],
        $body = null,
        string $protocol = "1.1",
        ?Trailers $trailers = null
    ) {
        $this->client = $client;
        $this->method = $method;
        $this->uri = $uri;
        $this->protocol = $protocol;

        if ($body !== null) {
            $this->setBody($body);
        }

        if (!empty($headers)) {
            $this->setHeaders($headers);
        }

        if ($trailers !== null) {
            $this->setTrailers($trailers);
        }
    }

    /**
     * @return \Amp\Http\Server\Driver\Client The client sending the request.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Retrieve the HTTP method used to make this request.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Sets the request HTTP method.
     *
     * @param string $method
     */
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * Retrieve the request URI.
     *
     * @return PsrUri
     */
    public function getUri(): PsrUri
    {
        return $this->uri;
    }

    /**
     * Sets a new URI for the request.
     *
     * @param PsrUri $uri
     */
    public function setUri(PsrUri $uri): void
    {
        $this->uri = $uri;
    }

    /**
     * This method returns the HTTP protocol version (e.g. "1.0", "1.1", "2.0") in use;
     * it has nothing to do with URI schemes like http:// or https:// ...
     *
     * @return string
     */
    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    /**
     * Sets a new protocol version number for the request.
     *
     * @param string $protocol
     */
    public function setProtocolVersion(string $protocol): void
    {
        $this->protocol = $protocol;
    }

    /**
     * Sets the headers from the given array. Any cookie headers will automatically populate the contained array of
     * RequestCookie objects.
     *
     * @param string[]|string[][] $headers
     */
    public function setHeaders(array $headers): void
    {
        $cookies = $this->cookies;

        try {
            parent::setHeaders($headers);
        } catch (\Throwable $e) {
            $this->cookies = $cookies;

            throw $e;
        }
    }

    /**
     * Sets the named header to the given value.
     *
     * @param string $name
     * @param string|string[] $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    public function setHeader(string $name, $value): void
    {
        if (($name[0] ?? ":") === ":") {
            throw new \Error("Header name cannot be empty or start with a colon (:)");
        }

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
    public function addHeader(string $name, $value): void
    {
        if (($name[0] ?? ":") === ":") {
            throw new \Error("Header name cannot be empty or start with a colon (:)");
        }

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
    public function removeHeader(string $name): void
    {
        parent::removeHeader($name);

        if (\stripos($name, "cookie") === 0) {
            $this->cookies = [];
        }
    }

    /**
     * Retrieve the request body.
     *
     * @return \Amp\Http\Server\RequestBody
     */
    public function getBody(): RequestBody
    {
        if ($this->body === null) {
            $this->body = new RequestBody(new InMemoryStream);
        }

        return $this->body;
    }

    /**
     * Sets the stream for the message body. Note that using a string will automatically set the Content-Length header
     * to the length of the given string. Using an InputStream or Body instance will remove the Content-Length header.
     *
     * @param RequestBody|InputStream|string|null $stringOrStream
     *
     * @throws \Error
     * @throws \TypeError
     */
    public function setBody($stringOrStream): void
    {
        if ($stringOrStream instanceof RequestBody) {
            $this->body = $stringOrStream;
            $this->removeHeader("content-length");
            return;
        }

        if ($stringOrStream instanceof InputStream) {
            $this->body = new RequestBody($stringOrStream);
            $this->removeHeader("content-length");
            return;
        }

        try {
            // Use method with string type declaration, so we don't need to implement our own check.
            $this->setBodyFromString($stringOrStream ?? "");
        } catch (\TypeError $e) {
            // Provide a better error message in case of a failure.
            throw new \TypeError(\sprintf(
                "The request body must a string, null, or an instance of %s",
                InputStream::class
            ));
        }
    }

    private function setBodyFromString(string $body): void
    {
        $this->body = new RequestBody(new InMemoryStream($body));

        if ($length = \strlen($body)) {
            $this->setHeader("content-length", (string) \strlen($body));
        } elseif (!\in_array($this->method, ["GET", "HEAD", "OPTIONS", "TRACE"])) {
            $this->setHeader("content-length", "0");
        } else {
            $this->removeHeader("content-length");
        }
    }

    /**
     * @return RequestCookie[]
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @param string $name Name of the cookie.
     *
     * @return RequestCookie|null
     */
    public function getCookie(string $name): ?RequestCookie
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Adds a cookie to the request.
     *
     * @param RequestCookie $cookie
     */
    public function setCookie(RequestCookie $cookie): void
    {
        $this->cookies[$cookie->getName()] = $cookie;
        $this->setHeadersFromCookies();
    }

    /**
     * Removes a cookie from the request.
     *
     * @param string $name
     */
    public function removeCookie(string $name): void
    {
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
    private function setCookiesFromHeaders(): void
    {
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
    private function setHeadersFromCookies(): void
    {
        $values = [];

        foreach ($this->cookies as $cookie) {
            $values[] = (string) $cookie;
        }

        $this->setHeader("cookie", $values);
    }

    /**
     * @return mixed[] An array of all request attributes in the request's mutable local storage, indexed by name.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Check whether a variable with the given name exists in the request's mutable local storage.
     *
     * Each request has its own mutable local storage to which request handlers and middleware may read and write data.
     * Other request handlers or middleware which are aware of this data can then access it without the server being
     * tightly coupled to specific implementations.
     *
     * @param string $name Name of the attribute, should be namespaced with a vendor and package namespace like classes.
     *
     * @return bool
     */
    public function hasAttribute(string $name): bool
    {
        return \array_key_exists($name, $this->attributes);
    }

    /**
     * Retrieve a variable from the request's mutable local storage.
     *
     * Each request has its own mutable local storage to which request handlers and middleware may read and write data.
     * Other request handlers or middleware which are aware of this data can then access it without the server being
     * tightly coupled to specific implementations.
     *
     * @param string $name Name of the attribute, should be namespaced with a vendor and package namespace like classes.
     *
     * @return mixed
     *
     * @throws MissingAttributeError If an attribute with the given name does not exist.
     */
    public function getAttribute(string $name)
    {
        if (!$this->hasAttribute($name)) {
            throw new MissingAttributeError("The requested attribute '{$name}' does not exist");
        }

        return $this->attributes[$name];
    }

    /**
     * Assign a variable to the request's mutable local storage.
     *
     * Each request has its own mutable local storage to which request handlers and middleware may read and write data.
     * Other request handlers or middleware which are aware of this data can then access it without the server being
     * tightly coupled to specific implementations.
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
    public function setAttribute(string $name, $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Remove an attribute from the request's mutable local storage.
     *
     * @param string $name Name of the attribute, should be namespaced with a vendor and package namespace like classes.
     *
     * @throws MissingAttributeError If an attribute with the given name does not exist.
     */
    public function removeAttribute(string $name): void
    {
        if (!$this->hasAttribute($name)) {
            throw new MissingAttributeError("The requested attribute '{$name}' does not exist");
        }

        unset($this->attributes[$name]);
    }

    /**
     * Remove all attributes from the request's mutable local storage.
     */
    public function removeAttributes(): void
    {
        $this->attributes = [];
    }

    /**
     * @return Trailers|null
     */
    public function getTrailers(): ?Trailers
    {
        return $this->trailers;
    }

    /**
     * @param Trailers $trailers
     */
    public function setTrailers(Trailers $trailers): void
    {
        $this->trailers = $trailers;
    }

    /**
     * Removes any trailer headers from the request.
     */
    public function removeTrailers(): void
    {
        $this->trailers = null;
    }
}
