<?php

namespace Aerys;

class ParsedBody {
    private $fields;
    private $metadata;
    private $names;

    public function __construct(array $fields, array $metadata = []) {
        $this->fields = $fields;
        $this->metadata = $metadata;
    }

    /**
     * Fetch a string parameter (or null if it doesn't exist)
     *
     * @param string $name
     * @return string|null
     */
    public function getString(string $name) {
        return \is_string($this->fields[$name] ?? null) ? $this->fields[$name] : null;
    }

    /**
     * Fetch an array parameter (or null if it doesn't exist)
     *
     * @param string $name
     * @return array|null
     */
    public function getArray(string $name) {
        return \is_array($this->fields[$name] ?? null) ? $this->fields[$name] : null;
    }

    /**
     * Contains an array("filename" => $name, "mime" => $mimetype)
     * In case a filename is provided, mime is always set
     *
     * @param string $name
     * @return array
     */
    public function getMetadata(string $name) {
        return $this->metadata[$name] ?? null;
    }

    /**
     * Returns the names of the passed fields
     *
     * @return array
     */
    public function getNames(): array {
        return $this->names ?? $this->names = array_keys($this->fields);
    }

    /**
     * returns two associative fields and metadata arrays (like for extended abstractions...)
     *
     * @return array
     */
    public function getAll(): array {
        return ["fields" => $this->fields, "metadata" => $this->metadata];
    }
}