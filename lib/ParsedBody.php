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
    public function get(string $name) {
        return $this->fields[$name][0] ?? null;
    }

    /**
     * Fetch an array parameter (or empty array if it doesn't exist)
     *
     * @param string $name
     * @return array
     */
    public function getArray(string $name): array {
        return $this->fields[$name] ?? [];
    }

    /**
     * Contains an array("filename" => $name, "mime" => $mimetype)
     * Elements may be missing, but in case a filename is provided, mime is always set
     *
     * @param string $name
     * @return array|null
     */
    public function getMetadata(string $name) {
        return $this->metadata[$name][0] ?? null;
    }

    /**
     * Fetch an array of metadata
     *
     * @param string $name
     * @return array
     */
    public function getMetadataArray(string $name): array {
        return $this->metadata[$name] ?? [];
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
     * returns two associative fields and metadata arrays (like for extended abstractions or debug)
     *
     * @return array
     */
    public function getAll(): array {
        return ["fields" => $this->fields, "metadata" => $this->metadata];
    }
}