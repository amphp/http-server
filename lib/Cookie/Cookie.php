<?php

namespace Aerys\Cookie;

class Cookie {
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $value;

    /**
     * @param string $string Valid Set-Cookie header line.
     *
     * @return self
     *
     * @throws \Error Thrown if the string format is invalid.
     */
    public static function fromHeader(string $string): Cookie {
        $parts = array_map('trim', explode('=', $string, 2));

        if (2 !== count($parts)) {
            throw new \Error("Invalid cookie header format");
        }

        list($name, $value) = $parts;

        return new self($name, $value);
    }

    public function __construct(string $name, $value = '') {
        $this->name = $this->filterValue($name);
        $this->value = $this->filterValue($value);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getValue(): string {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function toHeader(): string {
        return $this->encode($this->name) . '=' . $this->encode($this->value);
    }

    /**
     * @param string $value
     * @return string mixed
     *
     * @throws \Error If the value is invalid.
     */
    protected function filterValue($value): string {
        $value = (string) $value;

        if (preg_match("/[^\x21\x23-\x23\x2d-\x3a\x3c-\x5b\x5d-\x7e]/", $value)) {
            throw new \Error('Invalid cookie header value.');
        }

        return $this->decode($value);
    }

    /**
     * Escapes URI value.
     *
     * @param string $value
     *
     * @return string
     */
    private function encode(string $value): string {
        return preg_replace_callback(
            '/(?:[^A-Za-z0-9_\-\.~!\'\(\)\*]+|%(?![A-Fa-f0-9]{2}))/',
            function (array $matches) {
                return rawurlencode($matches[0]);
            },
            $value
        );
    }
    /**
     * Decodes all URL encoded characters.
     *
     * @param string $string
     *
     * @return string
     */
    private function decode(string $string): string {
        return rawurldecode($string);
    }
}
