<?php

namespace Aerys;

use Aerys\Cookie\MetaCookie;
use Amp\ByteStream\InputStream;
use Amp\Socket\Socket;

class Response {
    /**  @var string[] */
    private $headerNameMap = [];

    /** @var string[][] */
    private $headers = [];

    /** @var \Amp\ByteStream\InputStream  */
    private $body;

    /** @var int HTTP status code. */
    private $status;

    /** @var string Response reason. */
    private $reason;

    /** @var \Aerys\Cookie\MetaCookie[] */
    private $cookies = [];

    /** @var array */
    private $push = [];

    /** @var array|null */
    private $detach;

    /**
     * @param int $code Status code.
     * @param string[][] $headers
     * @param \Amp\ByteStream\InputStream|null $stream
     * @param string|null $reason Status code reason.
     *
     * @throws \Error If one of the arguments is invalid.
     */
    public function __construct(
        InputStream $stream = null,
        array $headers = [],
        int $code = 200,
        string $reason = null
    ) {
        $this->status = $this->validateStatusCode($code);
        $this->reason = $reason === null
            ? (HTTP_REASON[$this->status] ?? "Unknown reason")
            : $this->filterReason($reason);

        if (!empty($headers)) {
            $this->setHeaders($headers);
        }

        if (isset($this->headers['set-cookie'])) {
            $this->setCookiesFromHeaders();
        }

        $this->body = $stream ?: new NullBody;
    }

    /**
     * Returns the response headers as a string-indexed array of arrays of strings or an empty array if no headers
     * have been set.
     *
     * @return string[][]
     */
    public function getHeaders(): array {
        return $this->headers;
    }

    /**
     * Returns the array of values for the given header or an empty array if the header does not exist.
     *
     * @param string $name
     *
     * @return string[]
     */
    public function getHeaderArray(string $name): array {
        return $this->headers[\strtolower($name)] ?? [];
    }

    /**
     * Returns the value of the given header. If multiple headers were present for the named header, only the first
     * header value will be returned. Use getHeaderArray() to return an array of all values for the particular header.
     * Returns null if the header does not exist.
     *
     * @param string $name
     *
     * @return string|null
     */
    public function getHeader(string $name) {
        return $this->headers[\strtolower($name)][0] ?? null;
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
     * Sets the stream for the message body.
     *
     * @param \Amp\ByteStream\InputStream $stream
     */
    public function setBody(InputStream $stream) {
        $this->body = $stream;
    }

    /**
     * Sets the headers from the given array.
     *
     * @param string[] $headers
     */
    public function setHeaders(array $headers) {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
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
        \assert($this->isNameValid($name), "Header name is invalid");
        \assert($this->isValueValid($name), "Header value is invalid");

        $name = \strtolower($name);
        $this->headers[$name] = [$value];

        if ('set-cookie' === $name) {
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
        \assert($this->isNameValid($name), "Header name is invalid");
        \assert($this->isValueValid($name), "Header value is invalid");

        $name = \strtolower($name);
        if (isset($this->headers[$name])) {
            $this->headers[$name][] = $value;
        } else {
            $this->headers = [$value];
        }

        if ('set-cookie' === $name) {
            $this->setCookiesFromHeaders();
        }
    }

    /**
     * Removes the given header if it exists.
     *
     * @param string $name
     */
    public function removeHeader(string $name) {
        $name = \strtolower($name);
        unset($this->headers[$name]);

        if ('set-cookie' === $name) {
            $this->cookies = [];
        }
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    private function isNameValid(string $name): bool {
        return (bool) preg_match('/^[A-Za-z0-9`~!#$%^&_|\'\-]+$/', $name);
    }

    /**
     * Determines if the given value is a valid header value.
     *
     * @param mixed|mixed[] $values
     *
     * @return bool
     *
     * @throws \Error If the given value cannot be converted to a string and is not an array of values that can be
     *     converted to strings.
     */
    private function isValueValid($values): bool {
        if (!is_array($values)) {
            $values = [$values];
        }

        foreach ($values as $value) {
            if (is_numeric($value) || is_null($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $value = (string) $value;
            } elseif (!is_string($value)) {
                return false;
            }

            if (preg_match("/[^\t\r\n\x20-\x7e\x80-\xfe]|\r\n/", $value)) {
                return false;
            }
        }

        return true;
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
        if ('' !== $this->reason) {
            return $this->reason;
        }

        return HTTP_REASON[$this->status] ?? '';
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
        $this->reason = $this->filterReason($reason);
    }

    /**
     * @return \Aerys\Cookie\MetaCookie[]
     */
    public function getCookies(): array {
        return $this->cookies;
    }

    /**
     * @return \Aerys\Cookie\MetaCookie|null
     */
    public function getCookie(string $name) {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Adds a cookie to the response.
     *
     * @param string $name
     * @param mixed $value
     * @param int $expires Unix timestamp of expiration.
     * @param string|null $path Optional path.
     * @param string|null $domain Optional domain.
     * @param bool $secure Send only on secure requests.
     * @param bool $httpOnly Send only on http requests.
     */
    public function setCookie(
        string $name,
        $value = '',
        int $expires = 0,
        string $path = null,
        string $domain = null,
        bool $secure = false,
        bool $httpOnly = false
    ) {
        $this->cookies[$name] = new MetaCookie($name, $value, $expires, $path, $domain, $secure, $httpOnly);
        $this->setHeadersFromCookies();
    }

    /**
     * Removes a cookie from the response.
     *
     * @param string $name
     */
    public function removeCookie(string $name) {
        unset($this->cookies[$name]);
        $this->setHeadersFromCookies();
    }

    /**
     * @param string|int $code
     *
     * @return int
     *
     * @throws \Error
     */
    protected function validateStatusCode(int $code): int {
        if ($code < 100 || $code > 599) {
            throw new \Error(
                'Invalid status code. Must be an integer between 100 and 599, inclusive.'
            );
        }

        return $code;
    }

    /**
     * @param string|null $reason
     *
     * @return string
     */
    protected function filterReason(string $reason = null): string {
        return $reason ?? (HTTP_REASON[$this->status] ?? "Unknown reason");
    }

    /**
     * Sets cookies based on headers.
     *
     * @throws \Error
     */
    private function setCookiesFromHeaders() {
        $this->cookies = [];

        $headers = $this->getHeaderArray('set-cookie');

        foreach ($headers as $line) {
            $cookie = MetaCookie::fromHeader($line);
            $this->cookies[$cookie->getName()] = $cookie;
        }
    }

    /**
     * Sets headers based on cookie values.
     */
    private function setHeadersFromCookies() {
        $values = [];

        foreach ($this->cookies as $cookie) {
            $values[] = $cookie->toHeader();
        }

        $this->setHeader('set-cookie', $values);
    }

    /**
     * @return string[][]
     */
    public function getPush(): array {
        return $this->push;
    }

    /**
     */
    public function push(string $url, array $headers = null) {
        \assert((function ($headers) {
            foreach ($headers ?? [] as $name => $header) {
                if (\is_int($name)) {
                    if (count($header) != 2) {
                        return false;
                    }
                    list($name) = $header;
                }
                if ($name[0] == ":" || !strncasecmp("host", $name, 4)) {
                    return false;
                }
            }
            return true;
        })($headers), "Headers must not contain colon prefixed headers or a Host header. Use a full URL if necessary, the method is always GET.");

        $this->push[$url] = $headers;
    }

    /**
     * @return bool True if a detach callback has been set, false if none.
     */
    public function isDetached(): bool {
        return $this->detach !== null;
    }

    /**
     * @param callable $detach Callback invoked once the response has been written to the client. The callback is given
     *     an instance of \Amp\Socket\ServerSocket as the first parameter, followed by the given arguments.
     * @param array ...$args Arguments to pass to the detach callback.
     */
    public function detach(callable $detach, ...$args) {
        $this->detach = [$detach, $args];
    }

    /**
     * @internal
     *
     * @return \Aerys\Internal\Response
     */
    public function export(): Internal\Response {
        $ires = new Internal\Response;
        $ires->headers = $this->headers;
        $ires->status = $this->status;
        $ires->reason = $this->reason;
        $ires->push = $this->push;
        $ires->body = $this->body;
        $ires->detach = $this->detach;
        return $ires;
    }
}
