<?php

namespace Amp\Http\Server\Internal;

/** @internal */
abstract class Message {
    /** @var string[][] */
    private $headers = [];

    /**
     * Returns the headers as a string-indexed array of arrays of strings or an empty array if no headers
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
     * Returns the value of the given header. If multiple headers are present for the named header, only the first
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
     * Sets the headers from the given array.
     *
     * @param string[]|string[][] $headers
     */
    public function setHeaders(array $headers) {
        // Ensure this is an atomic operation, either all headers are set or none.
        $before = $this->headers;

        try {
            foreach ($headers as $name => $value) {
                $this->setHeader($name, $value);
            }
        } catch (\Throwable $e) {
            $this->headers = $before;

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
        \assert($this->isNameValid($name), "Invalid header name");

        if (\is_array($value)) {
            if (!$value) {
                $this->removeHeader($name);
                return;
            }

            $value = \array_values(\array_map("strval", $value));
        } else {
            $value = [(string) $value];
        }

        \assert($this->isValueValid($value), "Invalid header value");

        $name = \strtolower($name);
        $this->headers[$name] = $value;
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
        \assert($this->isNameValid($name), "Invalid header name");

        if (\is_array($value)) {
            if (!$value) {
                return;
            }

            $value = \array_values(\array_map("strval", $value));
        } else {
            $value = [(string) $value];
        }

        \assert($this->isValueValid($value), "Invalid header value");

        $name = \strtolower($name);
        if (isset($this->headers[$name])) {
            $this->headers[$name] = \array_merge($this->headers[$name], $value);
        } else {
            $this->headers[$name] = $value;
        }
    }

    /**
     * Removes the given header if it exists.
     *
     * @param string $name
     */
    public function removeHeader(string $name) {
        unset($this->headers[\strtolower($name)]);
    }

    /**
     * Checks if given header exists.
     *
     * @param string $name
     *
     * @return bool
     */
    public function hasHeader(string $name): bool {
        return isset($this->headers[\strtolower($name)]);
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    private function isNameValid(string $name): bool {
        return (bool) \preg_match('/^[A-Za-z0-9`~!#$%^&_|\'\-:]+$/', $name);
    }

    /**
     * Determines if the given value is a valid header value.
     *
     * @param string[] $values
     *
     * @return bool
     *
     * @throws \Error If the given value cannot be converted to a string and is not an array of values that can be
     *     converted to strings.
     */
    private function isValueValid(array $values): bool {
        foreach ($values as $value) {
            if (\preg_match("/[^\t\r\n\x20-\x7e\x80-\xfe]|\r\n/", $value)) {
                return false;
            }
        }

        return true;
    }
}
