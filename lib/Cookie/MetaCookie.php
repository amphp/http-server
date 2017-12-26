<?php

namespace Aerys\Cookie;

class MetaCookie extends Cookie {
    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $domain;

    /**
     * @var int
     */
    private $expires = 0;

    /**
     * @var bool
     */
    private $secure = false;

    /**
     * @var bool
     */
    private $httpOnly = false;

    /**
     * @param string $string Valid Set-Cookie header line.
     *
     * @return self
     *
     * @throws \Error Thrown if the string format is invalid.
     */
    public static function fromHeader(string $string) { /* : ?self */
        $parts = array_filter(array_map('trim', explode(';', $string)));

        if (empty($parts) || !strpos($parts[0], '=')) {
            return null;
        }

        list($name, $value) = array_map('trim', explode('=', array_shift($parts), 2));

        $expires = 0;
        $path = '';
        $domain = '';
        $secure = false;
        $httpOnly = false;

        foreach ($parts as $part) {
            $pieces = array_map('trim', explode('=', $part, 2));
            $key = strtolower($pieces[0]);

            if (1 === count($pieces)) {
                switch ($key) {
                    case 'secure':
                        $secure = true;
                        break;

                    case 'httponly':
                        $httpOnly = true;
                        break;
                }
            } else {
                switch ($key) {
                    case 'expires':
                        $time = \DateTime::createFromFormat('D, j M Y G:i:s T', $pieces[1]);
                        if (false === $time) {
                            break;
                        }

                        $time = $time->getTimestamp();
                        $expires = 0 === $expires ? $time : min($time, $expires);
                        break;

                    case 'max-age':
                        $time = trim($pieces[1]);
                        if (ctype_digit($time)) {
                            break;
                        }

                        $time = time() + (int) $time;
                        $expires = 0 === $expires ? $time : min($time, $expires);
                        break;

                    case 'path':
                        $path = $pieces[1];
                        break;

                    case 'domain':
                        $domain = $pieces[1];
                        break;
                }
            }
        }

        return new self($name, $value, $expires, $path, $domain, $secure, $httpOnly);
    }

    /**
     * @param string $name
     * @param string $value
     * @param int $expires
     * @param string $path
     * @param string $domain
     * @param bool $secure
     * @param bool $httpOnly
     */
    public function __construct(
        string $name,
        $value = '',
        int $expires = 0,
        string $path = null,
        string $domain = null,
        bool $secure = false,
        bool $httpOnly = false
    ) {
        \assert($this->isValueValid($path), "Invalid path");
        \assert($this->isValueValid($domain), "Invalid domain");

        parent::__construct($name, $value);

        $this->expires = $expires;
        $this->path = $this->decode($path);
        $this->domain = $this->decode($domain);
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;
    }

    /**
     * {@inheritdoc}
     */
    public function getExpires(): int {
        return $this->expires;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     */
    public function getDomain(): string {
        return $this->domain;
    }

    /**
     * {@inheritdoc}
     */
    public function isSecure(): bool {
        return $this->secure;
    }

    /**
     * {@inheritdoc}
     */
    public function isHttpOnly(): bool {
        return $this->httpOnly;
    }

    /**
     * {@inheritdoc}
     */
    public function toHeader(): string {
        $line = parent::toHeader();

        if (0 !== $this->expires) {
            $line .= '; Expires=' . $this->encodeDate($this->expires);
        }

        if ('' !== $this->path) {
            $line .= '; Path=' . $this->encodePath($this->path);
        }

        if ('' !== $this->domain) {
            $line .= '; Domain=' . $this->domain;
        }

        if ($this->secure) {
            $line .= '; Secure';
        }

        if ($this->httpOnly) {
            $line .= '; HttpOnly';
        }

        return $line;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    protected function encodePath($path) {
        return preg_replace_callback(
            '/(?:[^A-Za-z0-9_\-\.~\/:%]+|%(?![A-Fa-f0-9]{2}))/',
            function (array $matches) {
                return rawurlencode($matches[0]);
            },
            $path
        );
    }

    /**
     * @param int $date
     *
     * @return string
     */
    protected function encodeDate($date) {
        return gmdate('D, j M Y G:i:s T', $date);
    }
}
