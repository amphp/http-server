<?php

namespace Aerys;

class Response {
    private $status = 200;
    private $reason = '';
    private $headers = '';
    private $body = NULL;

    /**
     * Get the status code for this response
     *
     * @return int
     */
    public function getStatus() {
        return $this->status;
    }

    /**
     * Assign a response status code
     *
     * @param int $status
     * @throws \InvalidArgumentException On invalid parameter type
     * @throws \DomainException On status code outside the inclusive set [100|599]
     * @return object Returns the current object instance
     */
    public function setStatus($status) {
        if (!(is_int($status) || ctype_digit($status))) {
            throw new \InvalidArgumentException(
                sprintf('Invalid response status type: %s', gettype($status))
            );
        } elseif ($status < 100 || $status > 599) {
            throw new \DomainException(
                sprintf('Invalid response status code: %d', $status)
            );
        } else {
            $this->status = (int) $status;
        }

        return $this;
    }

    /**
     * Get the assigned reason phrase for this response
     *
     * @return string
     */
    public function getReason() {
        return $this->reason;
    }

    /**
     * Assign an optional reason phrase (e.g. Not Found) to accompany the status code
     *
     * @param string $reason
     * @return object Returns the current object instance
     */
    public function setReason($reason) {
        $this->reason = (string) $reason;

        return $this;
    }

    /**
     * Retrieve the value from the first occurrence of the specified header field
     *
     * NOTE: This method returns only the first value for the specified field. Use
     * Response::getHeaderArray() if you need access to all assigned headers for
     * a given field.
     *
     * @param string $field
     * @throws \DomainException If the specified header field does not exist
     * @return string
     */
    public function getHeader($field) {
        $fieldKey = rtrim($field, " :") . ':';
        $tok = strtok("\r\n" . $this->headers, "\r\n");
        while ($tok !== FALSE) {
            if (stripos($tok, $fieldKey) === 0) {
                return trim(substr($tok, strlen($fieldKey)));
            }
            $tok = strtok("\r\n");
        }

        throw new \DomainException(
            sprintf("Header field not found: %s", $field)
        );
    }

    /**
     * Like Response::getHeader() but returns NULL if the header doesn't exist instead of throwing
     *
     * NOTE: This method returns only the first value for the specified field. Use
     * Response::getHeaderArray() if you need access to all assigned headers for
     * a given field.
     *
     * @param string $field
     * @return string|NULL
     */
    public function getHeaderSafe($field) {
        $fieldKey = rtrim($field, " :") . ':';
        $tok = strtok("\r\n" . $this->headers, "\r\n");
        while ($tok !== FALSE) {
            if (stripos($tok, $fieldKey) === 0) {
                return trim(substr($tok, strlen($fieldKey)));
            }
            $tok = strtok("\r\n");
        }

        return NULL;
    }

    /**
     * Retrieve an array of header values assigned for the specified field
     *
     * HTTP allows multiple values for a given header field. Use this method to retrieve
     * all individually assigned values for a given field. If the specified header is not
     * assigned an empty array is returned.
     *
     * @param string $field
     * @return array
     */
    public function getHeaderArray($field) {
        $fieldKey = rtrim($field, " :") . ':';
        $fieldKeyLen = strlen($fieldKey);
        $values = [];

        $tok = strtok($this->headers, "\r\n");
        while ($tok !== FALSE) {
        if (stripos($tok, $fieldKey) === 0) {
                $values[] = trim(substr($tok, $fieldKeyLen));
                trim(substr($tok, $fieldKeyLen));
            }
            $tok = strtok("\r\n");
        }

        return $values;
    }

    /**
     * Retrieve a string of comma-concatenated headers for the specified field
     *
     * If the specified header is not assigned an empty string is returned.
     *
     * @param string $field
     * @return string|NULL
     */
    public function getHeaderFolded($field) {
        $fieldKey = rtrim($field, " :") . ':';
        $fieldKeyLen = strlen($fieldKey);
        $values = [];

        $tok = strtok($this->headers, "\r\n");
        while ($tok !== FALSE) {
            if (stripos($tok, $fieldKey) === 0) {
                $values[] = trim(substr($tok, $fieldKeyLen));
            }
            $tok = strtok("\r\n");
        }

        return $values ? implode(', ', $values) : NULL;
    }

    /**
     * Does the specified $field have a case-insensitive match for the specified $value?
     *
     * @param string $field
     * @param string $value
     * @return bool
     */
    public function hasHeaderMatch($field, $value) {
        $fieldKey = rtrim($field, " :") . ':';
        $fieldKeyLen = strlen($fieldKey);

        $tok = strtok($this->headers, "\r\n");
        while ($tok !== FALSE) {
            if (stripos($tok, $fieldKey) === 0 &&
                strcasecmp($value, trim(substr($tok, $fieldKeyLen))) === 0
            ) {
                return TRUE;
            } else {
                $tok = strtok("\r\n");
            }
        }

        return FALSE;
    }

    /**
     *
     */
    public function applyRawHeaderLines(array $lines) {
        $this->headers = "\r\n" . implode("\r\n", array_map('trim', $lines));

        return $this;
    }

    /**
     * Return an array of raw header lines assigned for this response
     *
     * @return array
     */
    public function getAllHeaderLines() {
        return $this->headers ? explode("\r\n", trim($this->headers)) : [];
    }

    /**
     * Return the raw header string imploded with CRLF \r\n bytes
     *
     * Example return value (line termination bytes shown for clarity)
     *
     *     Content-Length: 42\r\n
     *     Content-Type: text/plain; charset=utf-8\r\n
     *     X-My-Header: some value
     *
     * If no headers are assigned the return value is an empty string
     *
     * @return string
     */
    public function getRawHeaders() {
        return $this->headers;
    }

    /**
     * Does the specified header exist?
     *
     * @param string $field
     * @return bool
     */
    public function hasHeader($field) {
        $field = (string) $field;
        if (stripos($this->headers, "\r\n{$field}:") !== FALSE) {
            return TRUE;
        } elseif (stripos($this->headers, "{$field}:") === 0) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    /**
     * Append a new header without replacing previously assigned values for the same field
     *
     * @param string $field
     * @param string $value
     * @throws \InvalidArgumentException On bad parameter types
     * @throws \DomainException On invalid character in parameter strings
     * @return object Returns the current object instance
     */
    public function addHeader($field, $value) {
        $field = (string) $field;
        $this->validateHeaderValue($value);
        $this->headers .= "\r\n{$field}: {$value}";

        return $this;
    }

    private function validateHeaderField($field) {
        if (empty($field) || !is_string($field)) {
            throw new \InvalidArgumentException(
                "Header field expects a non-empty string at Argument 1"
            );
        }
    }

    private function validateHeaderValue($value) {
        if (empty($value)) {
            return;
        } elseif (!is_scalar($value)) {
            throw new \InvalidArgumentException(
                sprintf('Header value must be a scalar: %s', gettype($value))
            );
        } elseif (strpbrk($value, "\r\n") !== FALSE) {
            throw new \DomainException(
                'Header values must not contain CR (\r) or LF (\n) characters'
            );
        }
    }

    /**
     * Replace any occurences of $field with the specified header $value
     *
     * @param string $field
     * @param string $value
     * @throws \InvalidArgumentException On bad parameter types
     * @throws \DomainException On invalid character in parameter strings
     * @return object Returns the current object instance
     */
    public function setHeader($field, $value) {
        $field = (string) $field;
        $this->validateHeaderValue($value);
        $this->removeHeader($field);
        $this->headers .= "\r\n{$field}: {$value}";

        return $this;
    }

    /**
     * Assign all headers from an array mapping field name keys to value lists
     *
     * @param array
     * @throws \InvalidArgumentException On bad parameter types
     * @throws \DomainException On invalid character in parameter strings
     * @return object Returns the current object instance
     */
    public function setAllHeaders(array $headerMap) {
        foreach ($headerMap as $field => $value) {
            $value = is_array($value) ? $value : [$value];
            $this->removeHeader($field);
            foreach ($value as $v) {
                $this->addHeader($field, $value);
            }
        }

        return $this;
    }

    /**
     * Append all headers from an array mapping field name keys to value lists
     *
     * @param array
     * @throws \InvalidArgumentException On bad parameter types
     * @throws \DomainException On invalid character in parameter strings
     * @return object Returns the current object instance
     */
    public function addAllHeaders(array $headerMap) {
        foreach ($headerMap as $field => $value) {
            $value = is_array($value) ? $value : [$value];
            foreach ($value as $v) {
                $this->addHeader($field, $value);
            }
        }

        return $this;
    }

    /**
     * Remove all occurences of the specified header $field from the response
     *
     * @param string $field
     * @return object Returns the current object instance
     */
    public function removeHeader($field) {
        $newHeaders = [];
        $fieldKey = rtrim($field, " :") . ':';
        foreach (explode("\r\n", $this->headers) as $line) {
            if (stripos($line, $fieldKey) !== 0) {
                $newHeaders[] = $line;
            }
        }
        $this->headers = $newHeaders ? implode("\r\n", $newHeaders) : '';

        return $this;
    }

    /**
     * Clear all assigned headers from the response
     *
     * @return object Returns the current object instance
     */
    public function removeAllHeaders() {
        $this->headers = '';
    }

    /**
     * Is an entity body assigned for this response?
     *
     * @return bool
     */
    public function hasBody() {
        return $this->body != '';
    }

    /**
     * Retrieve the entity body assigned for this response
     *
     * @return mixed
     */
    public function getBody() {
        return $this->body;
    }

    /**
     * Assign a response entity body
     *
     * @param mixed $body
     * @return mixed Returns the current object instance
     */
    public function setBody($body) {
        $this->body = $body;

        return $this;
    }

    public function toList() {
        return [
            $this->status,
            $this->reason,
            $this->body,
            $this->headers,
        ];
    }

    public function toDict() {
        return [
            'status'  => $this->status,
            'reason'  => $this->reason,
            'body'    => $this->body,
            'headers' => $this->headers,
        ];
    }
}
