<?php

namespace Aerys;

use Aerys\Cookie\MetaCookie;
use Amp\ByteStream\InputStream;

class Response {
    /**  @var string[] */
    private $headerNameMap = [];

    /** @var string[][] */
    private $headers = [];

    /** @var \Amp\ByteStream\InputStream  */
    private $stream;

    /** @var int HTTP status code. */
    private $status;

    /** @var string Response reason. */
    private $reason;

    /** @var \Aerys\Cookie\MetaCookie[] */
    private $cookies = [];

    /** @var array */
    private $push = [];

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
            ? (HTTP_STATUS[$this->status] ?? "Unknown reason")
            : $this->filterReason($reason);

        if (!empty($headers)) {
            $this->setHeaders($headers);
        }

        if (isset($this->headers['set-cookie'])) {
            $this->setCookiesFromHeaders();
        }

        $this->stream = $stream ?: new NullBody;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders(): array {
        return $this->headers;
    }

    /**
     * {@inheritdoc}
     */
    public function hasHeader(string $name): bool {
        return isset($this->headerNameMap[strtolower($name)]);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderAsArray(string $name): array {
        $name = strtolower($name);

        if (!isset($this->headerNameMap[$name])) {
            return [];
        }

        $name = $this->headerNameMap[$name];

        return $this->headers[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader(string $name): string {
        $name = strtolower($name);

        if (!isset($this->headerNameMap[$name])) {
            return '';
        }

        $name = $this->headerNameMap[$name];

        return isset($this->headers[$name][0]) ? $this->headers[$name][0] : '';
    }

    /**
     * {@inheritdoc}
     */
    public function getBody(): InputStream {
        return $this->stream;
    }

    /**
     * {@inheritdoc}
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
        \assert($this->isHeaderNameValid($name), "Header name is invalid");

        $name = strtolower($name);
        $this->headers[$name] = $this->filterHeader($value);

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
        \assert($this->isHeaderNameValid($name), "Header name is invalid");

        $name = strtolower($name);
        if (isset($this->headers[$name])) {
            $this->headers[$name] = \array_merge($this->headers[$name], $this->filterHeader($value));
        } else {
            $this->headers = $this->filterHeader($value);
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
        $name = strtolower($name);
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
    private function isHeaderNameValid(string $name): bool {
        return (bool) preg_match('/^[A-Za-z0-9`~!#$%^&_|\'\-]+$/', $name);
    }

    /**
     * Converts a given header value to an integer-indexed array of strings.
     *
     * @param mixed|mixed[] $values
     *
     * @return string[]
     *
     * @throws \Error If the given value cannot be converted to a string and is not an array of values that can be
     *     converted to strings.
     */
    private function filterHeader($values): array {
        if (!is_array($values)) {
            $values = [$values];
        }

        $lines = [];

        foreach ($values as $value) {
            if (is_numeric($value) || is_null($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $value = (string) $value;
            } elseif (!is_string($value)) {
                throw new \Error('Header values must be strings or an array of strings.');
            }

            if (preg_match("/[^\t\r\n\x20-\x7e\x80-\xfe]|\r\n/", $value)) {
                throw new \Error('Invalid character(s) in header value.');
            }

            $lines[] = $value;
        }

        return $lines;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(): int {
        return $this->status;
    }

    /**
     * {@inheritdoc}
     */
    public function getReason(): string {
        if ('' !== $this->reason) {
            return $this->reason;
        }

        return isset(HTTP_STATUS[$this->status]) ? HTTP_REASON[$this->status] : '';
    }

    /**
     * {@inheritdoc}
     */
    public function setStatus(int $code, string $reason = null) {
        $this->status = $this->validateStatusCode($code);
        $this->reason = $this->filterReason($reason);
    }

    /**
     * {@inheritdoc}
     */
    public function getCookies(): array {
        return $this->cookies;
    }

    /**
     * {@inheritdoc}
     */
    public function hasCookie(string $name): bool {
        return isset($this->cookies[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function getCookie(string $name) {
        return $this->cookies[$name] ?? null;
    }

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
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
        return $reason ?? (HTTP_STATUS[$this->status] ?? "Unknown reason");
    }

    /**
     * Sets cookies based on headers.
     *
     * @throws \Error
     */
    private function setCookiesFromHeaders() {
        $this->cookies = [];

        $headers = $this->getHeaderAsArray('set-cookie');

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
}
