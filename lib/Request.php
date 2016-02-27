<?php

namespace Aerys;

interface Request {
    /**
     * Retrieve the HTTP method used to make this request
     *
     * @return string
     */
    public function getMethod(): string;

    /**
     * Retrieve the request URI in the form /some/resource/foo?bar=1&baz=2
     *
     * @return string
     */
    public function getUri(): string;

    /**
     * Retrieve the HTTP protocol version number used by this request
     *
     * This method returns the HTTP protocol version (e.g. "1.0", "1.1", "2.0") in use;
     * it has nothing to do with URI schemes like http:// or https:// ...
     *
     * @return string
     */
    public function getProtocolVersion(): string;

    /**
     * Retrieve the first occurrence of specified header in the message
     *
     * If multiple headers were received for the specified field only the
     * value of the first header is returned. Applications may use
     * Request::getHeaderArray() to retrieve a list of all header values
     * received for a given field.
     * 
     * All header $field names are treated case-insensitively.
     *
     * A null return indicates the requested header field was not present.
     *
     * @param string $field
     * @return string|null
     */
    public function getHeader(string $field);

    /**
     * Retrieve the specified header as an array of each of its occurrences in the request
     *
     * All header $field names are treated case-insensitively.
     *
     * An empty return array indicates that the header was not present in the request.
     *
     * @param string $field
     * @return array
     */
    public function getHeaderArray(string $field): array;

    /**
     * Retrieve an array of all headers in the message
     *
     * The returned array uses header names normalized to all-lowercase for
     * simplified querying via isset().
     *
     * @return array
     */
    public function getAllHeaders(): array;

    /**
     * Retrieve the streaming request entity body
     *
     * @TODO add documentation for how the body object is used
     * @param int $bodySize maximum body size
     *
     * @return \Aerys\Body
     */
    public function getBody(int $bodySize = -1): Body;

    /**
     * Retrieve an associative array of query string parameters/values
     *
     * This method's result is equivalent to the $_GET superglobal in
     * traditional PHP web SAPI applications.
     *
     * @return array
     */
    public function getQueryVars(): array;

    /**
     * Retrieve a cookie
     *
     * @param string $name
     * @return string|null
     */
    public function getCookie(string $name);

    /**
     * Retrieve a variable from the request's mutable local storage
     *
     * Each request has its own mutable local storage to which application
     * callables and middleware may read and write data. Other callables
     * which are aware of this data can then access it without the server
     * being tightly coupled to specific implementations.
     *
     * @param string $key
     * @return mixed
     */
    public function getLocalVar(string $key);

    /**
     * Assign a variable to the request's mutable local storage
     *
     * Each request has its own mutable local storage to which application
     * callables and middleware may read and write data. Other callables
     * which are aware of this data can then access it without the server
     * being tightly coupled to specific implementations.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setLocalVar(string $key, $value);

    /**
     * Retrieve an associative array of extended information about the underlying connection
     *
     * Keys:
     *      - client_port
     *      - client_addr
     *      - server_port
     *      - server_addr
     *      - is_encrypted
     *      - crypto_info = [protocol, cipher_name, cipher_bits, cipher_version]
     *
     * If the underlying connection is not encrypted the crypto_info array is empty.
     *
     * @return array
     */
    public function getConnectionInfo(): array;
}
