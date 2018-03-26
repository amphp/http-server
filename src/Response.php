<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Status;
use Amp\Loop;
use League\Uri;

final class Response extends Internal\Message {
    /** @var \Amp\ByteStream\InputStream  */
    private $body;

    /** @var int HTTP status code. */
    private $status;

    /** @var string Response reason. */
    private $reason;

    /** @var ResponseCookie[] */
    private $cookies = [];

    /** @var array */
    private $push = [];

    /** @var array|null */
    private $upgrade;

    /** @var callable[] */
    private $onDispose = [];

    /**
     * @param \Amp\ByteStream\InputStream|string|null $stringOrStream
     * @param string[][] $headers
     * @param int $code Status code.
     *
     * @throws \Error If one of the arguments is invalid.
     */
    public function __construct(
        int $code = Status::OK,
        array $headers = [],
        $stringOrStream = null
    ) {
        $this->status = $this->validateStatusCode($code);
        $this->reason = Status::getReason($this->status);

        $this->setBody($stringOrStream);

        if (!empty($headers)) {
            $this->setHeaders($headers);
        }
    }

    public function __destruct() {
        foreach ($this->onDispose as $callable) {
            try {
                $callable();
            } catch (\Throwable $exception) {
                Loop::defer(function () use ($exception) {
                    throw $exception; // Forward uncaught exceptions to the loop error handler.
                });
            }
        }
    }

    /**
     * Returns the stream for the message body.
     *
     * @return \Amp\ByteStream\InputStream
     */
    public function getBody(): InputStream {
        return $this->body;
    }

    /**
     * Sets the stream for the message body. Note that using a string will automatically set the Content-Length header
     * to the length of the given string. Setting a stream will remove the Content-Length header.
     *
     * @param \Amp\ByteStream\InputStream|string|null $stringOrStream
     *
     * @throws \TypeError If the body given is not a string or instance of \Amp\ByteStream\InputStream
     */
    public function setBody($stringOrStream) {
        if ($stringOrStream instanceof InputStream) {
            $this->body = $stringOrStream;
            $this->removeHeader("content-length");
            return;
        }

        try {
            // Use method with string type declaration, so we don't need to implement our own check.
            $this->setBodyFromString($stringOrStream ?? "");
        } catch (\TypeError $e) {
            // Provide a better error message in case of a failure.
            throw new \TypeError("The response body must a string, null, or instance of " . InputStream::class);
        }
    }

    private function setBodyFromString(string $body) {
        $this->body = new InMemoryStream($body);
        $this->setHeader("content-length", (string) \strlen($body));
    }

    /**
     * Sets the headers from the given array. Any cookie headers will automatically populate the contained array of
     * ResponseCookie objects.
     *
     * @param string[]|string[][] $headers
     */
    public function setHeaders(array $headers) {
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
    public function setHeader(string $name, $value) {
        parent::setHeader($name, $value);

        if (\stripos($name, "set-cookie") === 0) {
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

        if (\stripos($name, "set-cookie") === 0) {
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

        if (\stripos($name, "set-cookie") === 0) {
            $this->cookies = [];
        }
    }

    /**
     * Returns the response status code.
     *
     * @return int
     */
    public function getStatus(): int {
        return $this->status;
    }

    /**
     * Returns the reason phrase describing the status code.
     *
     * @return string
     */
    public function getReason(): string {
        return $this->reason;
    }

    /**
     * Sets the response status code and reason phrase. Use null for the reason phrase to use the default phrase
     * associated with the status code.
     *
     * @param int $code 100 - 599
     * @param string|null $reason
     */
    public function setStatus(int $code, string $reason = null) {
        $this->status = $this->validateStatusCode($code);
        $this->reason = $reason ?? Status::getReason($this->status);

        if ($this->upgrade && $this->status !== Status::SWITCHING_PROTOCOLS) {
            $this->upgrade = null;
        }
    }

    /**
     * @return ResponseCookie[]
     */
    public function getCookies(): array {
        return $this->cookies;
    }

    /**
     * @param string $name Name of the cookie.
     *
     * @return ResponseCookie|null
     */
    public function getCookie(string $name) { /* : ?ResponseCookie */
        return $this->cookies[$name] ?? null;
    }

    /**
     * Adds a cookie to the response.
     *
     * @param ResponseCookie $cookie
     */
    public function setCookie(ResponseCookie $cookie) {
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
     * @param int $code
     *
     * @return int
     *
     * @throws \Error
     */
    private function validateStatusCode(int $code): int {
        if ($code < 100 || $code > 599) {
            throw new \Error(
                'Invalid status code. Must be an integer between 100 and 599, inclusive.'
            );
        }

        return $code;
    }

    /**
     * Sets cookies based on headers.
     *
     * @throws \Error
     */
    private function setCookiesFromHeaders() {
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
    private function setHeadersFromCookies() {
        $values = [];

        foreach ($this->cookies as $cookie) {
            $values[] = (string) $cookie;
        }

        $this->setHeader("set-cookie", $values);
    }

    /**
     * @return array
     */
    public function getPush(): array {
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
    public function push(string $url, array $headers = []) {
        \assert((function (array $headers) {
            foreach ($headers as $name => $header) {
                if ($name[0] === ":" || !\strncasecmp("host", $name, 4)) {
                    return false;
                }
            }
            return true;
        })($headers), "Headers must not contain colon prefixed headers or a Host header");

        try {
            $uri = Uri\Http::createFromString($url);
        } catch (Uri\Exception $exception) {
            throw new \Error($exception->getMessage());
        }

        $this->push[$url] = [$uri, $headers];
    }

    /**
     * @return bool True if a detach callback has been set, false if none.
     */
    public function isUpgraded(): bool {
        return $this->upgrade !== null;
    }

    /**
     * Sets a callback to be invoked once the response has been written to the client and changes the status of the
     * response to 101 (Switching Protocols). The callback may be removed by changing the status to something else.
     *
     * @param callable $upgrade Callback invoked once the response has been written to the client. The callback is given
     *     an instance of \Amp\Socket\ServerSocket as the first parameter, followed by the given arguments.
     */
    public function upgrade(callable $upgrade) {
        $this->upgrade = $upgrade;
        $this->status = Status::SWITCHING_PROTOCOLS;
        $this->reason = Status::getReason($this->status);
    }

    /**
     * Returns the upgrade function if present.
     *
     * @return callable|null Upgrade function.
     */
    public function getUpgradeCallable() { /* : ?callable */
        return $this->upgrade;
    }

    /**
     * Registers a function that is invoked when the Response is discarded. A response is discarded either once it has
     * been written to the client or if it gets replaced in a middleware chain.
     *
     * @param callable $onDispose
     */
    public function onDispose(callable $onDispose) {
        $this->onDispose[] = $onDispose;
    }
}
