<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\HttpMessage;
use Amp\Http\HttpRequest;
use Amp\Http\Server\Driver\Client;
use Psr\Http\Message\UriInterface as PsrUri;

/**
 * @psalm-import-type HeaderParamValueType from HttpMessage
 * @psalm-import-type HeaderParamArrayType from HttpMessage
 */
final class Request extends HttpRequest
{
    private ?RequestBody $body = null;

    /** @var array<non-empty-string, RequestCookie> */
    private array $cookies = [];

    /** @var array<non-empty-string, mixed> */
    private array $attributes = [];

    private ?Trailers $trailers = null;

    /**
     * @param Client $client The client sending the request.
     * @param non-empty-string $method HTTP request method.
     * @param PsrUri $uri The full URI being requested, including host, port, and protocol.
     * @param array<non-empty-string, string|string[]> $headers An array of strings or an array of string arrays.
     * @param string $protocol HTTP protocol version (e.g. 1.0, 1.1, or 2.0).
     * @param Trailers|null $trailers Trailers if request has trailers, or null otherwise.
     */
    public function __construct(
        private readonly Client $client,
        string $method,
        PsrUri $uri,
        array $headers = [],
        ReadableStream|string $body = '',
        private string $protocol = "1.1",
        ?Trailers $trailers = null
    ) {
        parent::__construct($method, $uri);

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
     * @return Client The client sending the request.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Sets the request HTTP method.
     */
    public function setMethod(string $method): void
    {
        parent::setMethod($method);
    }

    /**
     * Sets a new URI for the request.
     */
    public function setUri(PsrUri $uri): void
    {
        parent::setUri($uri);
    }

    /**
     * This method returns the HTTP protocol version (e.g. "1.0", "1.1", "2") in use;
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
     * @param HeaderParamArrayType $headers
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
     * Replace headers from the given array. Any cookie headers will automatically populate the contained array of
     * RequestCookie objects.
     *
     * @param HeaderParamArrayType $headers
     */
    public function replaceHeaders(array $headers): void
    {
        $cookies = $this->cookies;

        try {
            parent::replaceHeaders($headers);
        } catch (\Throwable $e) {
            $this->cookies = $cookies;

            throw $e;
        }
    }

    /**
     * Sets the named header to the given value.
     *
     * @param non-empty-string $name
     * @param HeaderParamValueType $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    public function setHeader(string $name, array|string $value): void
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
     * @param non-empty-string $name
     * @param HeaderParamValueType $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    public function addHeader(string $name, array|string $value): void
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

    public function setQueryParameter(string $key, array|string|null $value): void
    {
        parent::setQueryParameter($key, $value);
    }

    public function addQueryParameter(string $key, array|string|null $value): void
    {
        parent::addQueryParameter($key, $value);
    }

    public function setQueryParameters(array $parameters): void
    {
        parent::setQueryParameters($parameters);
    }

    public function replaceQueryParameters(array $parameters): void
    {
        parent::replaceQueryParameters($parameters);
    }

    public function removeQueryParameter(string $key): void
    {
        parent::removeQueryParameter($key);
    }

    public function removeQuery(): void
    {
        parent::removeQuery();
    }

    /**
     * Retrieve the request body.
     */
    public function getBody(): RequestBody
    {
        return $this->body ??= new RequestBody('');
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

        $this->body = new RequestBody($body);

        if ($length = \strlen($body)) {
            $this->setHeader("content-length", (string) $length);
        } elseif (!\in_array($this->getMethod(), ["GET", "HEAD", "OPTIONS", "TRACE"])) {
            $this->setHeader("content-length", "0");
        } else {
            $this->removeHeader("content-length");
        }
    }

    /**
     * @return array<non-empty-string, RequestCookie>
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
            $values[] = $cookie->toString();
        }

        $this->setHeader("cookie", $values);
    }

    /**
     * @return array<non-empty-string, mixed> An array of all request attributes in the request's mutable local storage,
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
     * @param non-empty-string $name Name of the attribute, should be namespaced with a vendor and package namespace
     *      like classes.
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
     * @param non-empty-string $name Name of the attribute, should be namespaced with a vendor and package namespace
     *      like classes.
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
     * @param non-empty-string $name Name of the attribute, should be namespaced with a vendor and package namespace
     *      like classes.
     * @param mixed $value Value of the attribute, might be any value.
     */
    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Remove an attribute from the request's mutable local storage.
     *
     * @param non-empty-string $name Name of the attribute, should be namespaced with a vendor and package namespace
     *      like classes.
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
