<?php

namespace Aerys;

use Amp\Uri\Uri;

class Request {
    /** @var \Aerys\Internal\ServerRequest */
    private $internalRequest;

    /**
     * @param \Aerys\Internal\ServerRequest $internalRequest
     */
    public function __construct(Internal\ServerRequest $internalRequest) {
        $this->internalRequest = $internalRequest;
    }

    /**
     * Retrieve the HTTP method used to make this request.
     *
     * @return string
     */
    public function getMethod(): string {
        return $this->internalRequest->method;
    }

    /**
     * Retrieve the request URI.
     *
     * @return \Amp\Uri\Uri
     */
    public function getUri(): Uri {
        return $this->internalRequest->uri;
    }

    /**
     * Retrieve the HTTP protocol version number used by this request.
     *
     * This method returns the HTTP protocol version (e.g. "1.0", "1.1", "2.0") in use;
     * it has nothing to do with URI schemes like http:// or https:// ...
     *
     * @return string
     */
    public function getProtocolVersion(): string {
        return $this->internalRequest->protocol;
    }

    /**
     * Retrieve the first occurrence of specified header in the message.
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
    public function getHeader(string $field) { /* : ?string */
        return $this->internalRequest->headers[strtolower($field)][0] ?? null;
    }

    /**
     * Retrieve the specified header as an array of each of its occurrences in the request.
     *
     * All header $field names are treated case-insensitively.
     *
     * An empty return array indicates that the header was not present in the request.
     *
     * @param string $field
     * @return array
     */
    public function getHeaderArray(string $field): array {
        return $this->internalRequest->headers[strtolower($field)] ?? [];
    }

    /**
     * Retrieve an array of all headers in the message.
     *
     * The returned array uses header names normalized to all-lowercase for
     * simplified querying via isset().
     *
     * @return array
     */
    public function getHeaders(): array {
        return $this->internalRequest->headers;
    }

    /**
     * Retrieve the streaming request entity body.
     *
     * @TODO add documentation for how the body object is used
     *
     * @param int|null $bodySize Maximum body size or null to use server default.
     *
     * @return \Aerys\Body
     */
    public function getBody(int $bodySize = null): Body {
        $ireq = $this->internalRequest;
        if ($bodySize !== null) {
            if ($bodySize > ($ireq->maxBodySize ?? $ireq->client->options->maxBodySize)) {
                $ireq->maxBodySize = $bodySize;
                $ireq->client->httpDriver->upgradeBodySize($this->internalRequest);
            }
        }

        return $ireq->body;
    }

    /**
     * Retrieve a cookie.
     *
     * @param string $name
     * @return \Aerys\Cookie\Cookie|null
     */
    public function getCookie(string $name) {
        $ireq = $this->internalRequest;

        return $ireq->cookies[$name] ?? null;
    }

    /**
     * Retrieve a variable from the request's mutable local storage.
     *
     * Each request has its own mutable local storage to which application
     * callables and middleware may read and write data. Other callables
     * which are aware of this data can then access it without the server
     * being tightly coupled to specific implementations.
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute(string $key) {
        return $this->internalRequest->locals[$key] ?? null;
    }

    /**
     * Assign a variable to the request's mutable local storage.
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
    public function setAttribute(string $key, $value) {
        $this->internalRequest->locals[$key] = $value;
    }

    /**
     * Retrieve an associative array of extended information about the underlying connection.
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
    public function getConnectionInfo(): array {
        $client = $this->internalRequest->client;
        return [
            "client_port" => $client->clientPort,
            "client_addr" => $client->clientAddr,
            "server_port" => $client->serverPort,
            "server_addr" => $client->serverAddr,
            "is_encrypted"=> $client->isEncrypted,
            "crypto_info" => $client->cryptoInfo,
        ];
    }


    /**
     * Retrieve a server option value.
     *
     * @param string $option The option to retrieve
     * @throws \Error on unknown option
     */
    public function getOption(string $option) {
        return $this->internalRequest->client->options->{$option};
    }

    /**
     * @return int Unix timestamp of the request time.
     */
    public function getTime(): int {
        return $this->internalRequest->time;
    }
}
