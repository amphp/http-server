<?php

namespace Aerys;

class Response {
    private $status = 200;
    private $reason = '';
    private $headers = '';
    private $body = NULL;
    private $exportCallback;

    /**
     * Get the status code for this response
     *
     * @return int
     */
    public function getStatus() {
        return $this->status;
    }

    /**
     * Assign the response status code (default: 200)
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
            $this->status = $status;
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
     * @throws \InvalidArgumentException
     * @return object Returns the current object instance
     */
    public function setReason($reason) {
        if (is_string($reason)) {
            $this->reason = $reason;
        } else {
            throw new \InvalidArgumentException(
                sprintf('Reason phrase must be a string; %s provided', gettype($reason))
            );
        }

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
        $this->validateHeaderField($field);
        $headers = "\r\n" . $this->headers;
        $lineStartPos = stripos($headers, "\r\n{$field}:");

        if ($lineStartPos === FALSE) {
            throw new \DomainException(
                sprintf("Header field not found: %s", $field)
            );
        } else {
            $fieldLen = strlen($field) + 3;
            $valueStart = $lineStartPos + $fieldLen;
            $valueLen = strpos($headers, "\r\n", $lineStartPos + 2) - $valueStart;

            return trim(substr($headers, $valueStart, $valueLen));
        }
    }

    /**
     * Retrieve an array of header values assigned for the specified field
     *
     * HTTP allows multiple values for a given header field. Use this method to retrieve
     * all individually assigned values for a given field.
     *
     * @param string $field
     * @return array
     */
    public function getHeaderArray($field) {
        $this->validateHeaderField($field);
        $headers = "\r\n" . $this->headers;
        $fieldLen = strlen($field) + 3;
        $values = [];

        while (($lineStartPos = stripos($headers, "\r\n{$field}:")) !== FALSE) {
            $valueStart = $lineStartPos + $fieldLen;
            $valueLen = strpos($headers, "\r\n", $lineStartPos + 2) - $valueStart;
            $values[] = trim(substr($headers, $valueStart, $valueLen));
        }

        return $values;
    }

    public function getHeaderMerged($field) {
        return implode(',', $this->getHeaderArray($field));
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
        return $this->headers ? explode("\r\n", $this->headers) : [];
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
        $this->validateHeaderField($field);

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
        $this->validateHeaderField($field);
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
                'Header values must not contain CR (\\r) or LF (\\n) characters'
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
        $this->validateHeaderField($field);
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
        $this->validateHeaderField($field);

        $removedHeaderCount = 0;
        $headers = $this->headers;
        while (($lineStartPos = stripos($headers, "\r\n{$field}:")) !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $lineStartPos + 2);
            $start = substr($headers, 0, $lineStartPos);
            $end = $lineEndPos ? substr($headers, $lineEndPos) : '';
            $headers = $start . $end;
            $removedHeaderCount++;
        }

        $this->headers = $headers;

        return $removedHeaderCount;
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
        return $this->body !== NULL;
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

    public function setExportCallback(callable $callback) {
        $this->exportCallback = $callback;
        
        return $this;
    }

    public function hasExportCallback() {
        return (bool) $this->exportCallback;
    }

    public function getExportCallback() {
        return $this->exportCallback;
    }

    public function toList() {

    }

    public function toDict() {

    }
}
