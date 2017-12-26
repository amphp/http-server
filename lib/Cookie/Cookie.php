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
    public static function fromHeader(string $string) { /* : ?self */
        $parts = array_map('trim', explode('=', $string, 2));

        if (2 !== count($parts)) {
            return null;
        }

        list($name, $value) = $parts;

        return new self($name, $value);
    }

    public function __construct(string $name, $value = '') {
        \assert($this->isValueValid($name), "Invalid cookie name");
        \assert($this->isValueValid($value), "Invalid cookie value");

        $this->name = $this->decode($name);
        $this->value = $this->decode($value);
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
     * @return bool
     */
    protected function isValueValid(string $value): bool {
        if (preg_match("/[^\x21\x23-\x23\x2d-\x3a\x3c-\x5b\x5d-\x7e]/", $value)) {
            return false;
        }

        return true;
    }

    /**
     * Escapes URI value.
     *
     * @param string $value
     *
     * @return string
     */
    protected function encode(string $value): string {
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
    protected function decode(string $string): string {
        return rawurldecode($string);
    }
}
