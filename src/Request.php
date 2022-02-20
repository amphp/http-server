<?php

namespace Amp\Http\Server;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Message;
use Amp\Http\Server\Driver\Client;
use Psr\Http\Message\UriInterface as PsrUri;

final class Request extends Message
{
    private ?RequestBody $body = null;

    /** @var RequestCookie[] */
    private array $cookies = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    private ?Trailers $trailers = null;

    /**
     * @param Client $client The client sending the request.
     * @param string $method HTTP request method.
     * @param PsrUri $uri The full URI being requested, including host, port, and protocol.
     * @param string[]|string[][] $headers An array of strings or an array of string arrays.
     * @param string $protocol HTTP protocol version (e.g. 1.0, 1.1, or 2.0).
     * @param Trailers|null $trailers Trailers if request has trailers, or null otherwise.
     */
    public function __construct(
        private Client $client,
        private string $method,
        private PsrUri $uri,
        array $headers = [],
        ReadableStream|string $body = '',
        private string $protocol = "1.1",
        ?Trailers $trailers = null
    ) {
        if ($body !== '') {
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
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Sets the request HTTP method.
     */
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * Retrieve the request URI.
     */
    public function getUri(): PsrUri
    {
        return $this->uri;
    }

    /**
     * Sets a new URI for the request.
     */
    public function setUri(PsrUri $uri): void
    {
        $this->uri = $uri;
    }

    /**
     * This method returns the HTTP protocol version (e.g. "1.0", "1.1", "2.0") in use;
     * it has nothing to do with URI schemes like http:// or https:// ...
     */
    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    /**
     * Sets a new protocol version number for the request.
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
     */
    public function getBody(): RequestBody
    {
        if ($this->body === null) {
            $this->body = new RequestBody(new ReadableBuffer());
        }

        return $this->body;
    }

    /**
     * Sets the stream for the message body. Note that using a string will automatically set the Content-Length header
     * to the length of the given string. Using an ReadableStream or Body instance will remove the Content-Length header.
     *
     * @throws \Error
     * @throws \TypeError
     */
    public function setBody(ReadableStream|string $body): void
    {
        if ($body instanceof ReadableStream) {
            $this->body = $body instanceof RequestBody ? $body : new RequestBody($body);
            $this->removeHeader("content-length");
            return;
        }

        $this->body = new RequestBody(new ReadableBuffer($body));

        if ($length = \strlen($body)) {
            $this->setHeader("content-length", (string) $length);
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
     */
    public function getCookie(string $name): ?RequestCookie
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Adds a cookie to the request.
     */
    public function setCookie(RequestCookie $cookie): void
    {
        $this->cookies[$cookie->getName()] = $cookie;
        $this->setHeadersFromCookies();
    }

    /**
     * Removes a cookie from the request.
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
     * @return array<string, mixed> An array of all request attributes in the request's mutable local storage,
     * indexed by name.
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
     * @throws MissingAttributeError If an attribute with the given name does not exist.
     */
    public function getAttribute(string $name): mixed
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
    public function setAttribute(string $name, mixed $value): void
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

    public function getTrailers(): ?Trailers
    {
        return $this->trailers;
    }

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
