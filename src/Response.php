<?php

namespace Amp\Http\Server;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Message;
use Amp\Http\Status;
use League\Uri;
use Revolt\EventLoop;

final class Response extends Message
{
    private ReadableStream $body;

    /** @var int HTTP status code. */
    private int $status;

    /** @var string Response reason. */
    private string $reason;

    /** @var ResponseCookie[] */
    private array $cookies = [];

    /** @var Push[] */
    private array $push = [];

    private ?\Closure $upgrade = null;

    /** @var \Closure[] */
    private array $onDispose = [];

    private ?Trailers $trailers = null;

    /**
     * @param int $status Status code.
     * @param string[]|string[][] $headers
     *
     * @throws \Error If one of the arguments is invalid.
     */
    public function __construct(
        int $status = Status::OK,
        array $headers = [],
        ReadableStream|string $body = '',
        ?Trailers $trailers = null
    ) {
        $this->status = $this->validateStatusCode($status);
        $this->reason = Status::getReason($this->status);

        $this->setBody($body);

        if ($headers) {
            $this->setHeaders($headers);
        }

        if ($trailers !== null) {
            $this->setTrailers($trailers);
        }
    }

    public function __destruct()
    {
        foreach ($this->onDispose as $onDispose) {
            EventLoop::queue($onDispose);
        }
    }

    /**
     * Returns the stream for the message body.
     */
    public function getBody(): ReadableStream
    {
        return $this->body;
    }

    /**
     * Sets the stream for the message body. Note that using a string will automatically set the Content-Length header
     * to the length of the given string. Setting a stream will remove the Content-Length header.
     */
    public function setBody(ReadableStream|string $body): void
    {
        if ($body instanceof ReadableStream) {
            $this->body = $body;
            $this->removeHeader("content-length");
            return;
        }

        $this->body = new ReadableBuffer($body);
        $this->setHeader("content-length", (string) \strlen($body));
    }

    /**
     * Sets the headers from the given array. Any cookie headers will automatically populate the contained array of
     * ResponseCookie objects.
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

        if (\stripos($name, "set-cookie") === 0) {
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

        if (\stripos($name, "set-cookie") === 0) {
            $this->setCookiesFromHeaders();
        }
    }

    /**
     * Removes the given header if it exists.
     */
    public function removeHeader(string $name): void
    {
        parent::removeHeader($name);

        if (\stripos($name, "set-cookie") === 0) {
            $this->cookies = [];
        }
    }

    /**
     * Returns the response status code.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Returns the reason phrase describing the status code.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Sets the response status code and reason phrase. Use null for the reason phrase to use the default phrase
     * associated with the status code.
     *
     * @param int $status 100 - 599
     */
    public function setStatus(int $status, string $reason = null): void
    {
        $this->status = $this->validateStatusCode($status);
        $this->reason = $reason ?? Status::getReason($this->status);

        if ($this->upgrade && $this->status !== Status::SWITCHING_PROTOCOLS) {
            $this->upgrade = null;
        }
    }

    /**
     * @return ResponseCookie[]
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @param string $name Name of the cookie.
     */
    public function getCookie(string $name): ?ResponseCookie
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Adds a cookie to the response.
     */
    public function setCookie(ResponseCookie $cookie): void
    {
        $this->cookies[$cookie->getName()] = $cookie;
        $this->setHeadersFromCookies();
    }

    /**
     * Removes a cookie from the response.
     */
    public function removeCookie(string $name): void
    {
        if (isset($this->cookies[$name])) {
            unset($this->cookies[$name]);
            $this->setHeadersFromCookies();
        }
    }

    /**
     * @throws \Error
     */
    private function validateStatusCode(int $status): int
    {
        if ($status < 100 || $status > 599) {
            throw new \Error(
                'Invalid status code. Must be an integer between 100 and 599, inclusive.'
            );
        }

        return $status;
    }

    /**
     * Sets cookies based on headers.
     *
     * @throws \Error
     */
    private function setCookiesFromHeaders(): void
    {
        $this->cookies = [];

        $headers = $this->getHeaderArray("set-cookie");

        foreach ($headers as $line) {
            $cookie = ResponseCookie::fromHeader($line);
            $this->cookies[$cookie->getName()] = $cookie;
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

        $this->setHeader("set-cookie", $values);
    }

    /**
     * @return Trailers|null Trailers to be written with the response.
     */
    public function getTrailers(): ?Trailers
    {
        return $this->trailers;
    }

    /**
     * @param Trailers
     */
    public function setTrailers(Trailers $trailers): void
    {
        $this->trailers = $trailers;
    }

    /**
     * Removes any trailer headers from the response.
     */
    public function removeTrailers(): void
    {
        $this->trailers = null;
    }

    /**
     * @return Push[]
     */
    public function getPushes(): array
    {
        return $this->push;
    }

    /**
     * Indicate resources which a client likely needs to fetch (e.g. Link: preload or HTTP/2 Server Push).
     *
     * @param string $url URL of resource to push to the client.
     * @param string[][] Additional headers to attach to the request.
     *
     * @throws \Error If the given url is invalid.
     */
    public function push(string $url, array $headers = []): void
    {
        try {
            $uri = Uri\Http::createFromString($url);
        } catch (\Exception $exception) {
            throw new \Error("Invalid push URI: " . $exception->getMessage(), 0, $exception);
        }

        $this->push[$url] = new Push($uri, $headers);
    }

    /**
     * @return bool True if an upgrade callback has been set, false if none.
     */
    public function isUpgraded(): bool
    {
        return $this->upgrade !== null;
    }

    /**
     * Sets a callback to be invoked once the response has been written to the client and changes the status of the
     * response to 101 (Switching Protocols) and removes any trailers. The callback may be removed by changing the
     * response status to any value other than 101.
     *
     * @param \Closure(Driver\UpgradedSocket, Request, Response):void $upgrade Callback invoked once the response has
     * been written to the client. The callback is given three parameters: an instance of {@see Driver\UpgradedSocket},
     * the original {@see Request} object, and this {@see Response} object.
     */
    public function upgrade(\Closure $upgrade): void
    {
        $this->upgrade = $upgrade;
        $this->status = Status::SWITCHING_PROTOCOLS;
        $this->reason = Status::getReason($this->status);

        $this->removeTrailers();
    }

    /**
     * Returns the upgrade function if present.
     *
     * @return \Closure|null Upgrade function.
     */
    public function getUpgradeHandler(): ?\Closure
    {
        return $this->upgrade;
    }

    /**
     * Registers a function that is invoked when the Response is discarded. A response is discarded either once it has
     * been written to the client or if it gets replaced in a middleware chain.
     *
     * @param \Closure():void $onDispose
     */
    public function onDispose(\Closure $onDispose): void
    {
        $this->onDispose[] = $onDispose;
    }
}
