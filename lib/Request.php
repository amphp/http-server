<?php

namespace Aerys;

use Amp\ByteStream\IteratorStream;
use Amp\Uri\Uri;

class Request {
    private $internalRequest;
    private $queryParams;
    private $body;

    /**
     * @param \Aerys\Internal\Request $internalRequest
     */
    public function __construct(Internal\Request $internalRequest) {
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
     * Retrieve the request URI in the form /some/resource/foo?bar=1&baz=2.
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
     * @param int $bodySize maximum body size
     *
     * @return \Aerys\Body
     */
    public function getBody(int $bodySize = -1): Body {
        $ireq = $this->internalRequest;
        if ($bodySize > -1) {
            if ($bodySize > ($ireq->maxBodySize ?? $ireq->client->options->maxBodySize)) {
                $ireq->maxBodySize = $bodySize;
                $ireq->client->httpDriver->upgradeBodySize($this->internalRequest);
            }
        }

        return $ireq->body;
    }

    /**
     * Retrieve one query string value of that name.
     *
     * @param string $name
     * @return string|null
     */
    public function getParam(string $name) { /* : ?string */
        return ($this->queryParams ?? $this->queryParams = $this->parseParams())[$name][0] ?? null;
    }

    /**
     * Retrieve a array of query string values.
     *
     * @param string $name
     * @return array
     */
    public function getParamArray(string $name): array {
        return ($this->queryParams ?? $this->queryParams = $this->parseParams())[$name] ?? [];
    }

    /**
     * Retrieve an associative array of an array of query string values.
     *
     * @return array
     */
    public function getAllParams(): array {
        return $this->queryParams ?? $this->queryParams = $this->parseParams();
    }

    private function parseParams() {
        if (empty($this->internalRequest->uriQuery)) {
            return $this->queryParams = [];
        }

        $pairs = explode("&", $this->internalRequest->uriQuery);
        if (count($pairs) > $this->internalRequest->client->options->maxInputVars) {
            throw new ClientSizeException;
        }

        $this->queryParams = [];
        foreach ($pairs as $pair) {
            $pair = explode("=", $pair, 2);
            // maxFieldLen should not be important here ... if it ever is, create an issue...
            $this->queryParams[urldecode($pair[0])][] = urldecode($pair[1] ?? "");
        }

        return $this->queryParams;
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
     * Adds a callback that is invoked when the client connection is closed or the response to the request has been
     * fully written.
     *
     * @param callable $onClose
     */
    public function onClose(callable $onClose) {
        $this->internalRequest->onClose[] = $onClose;
    }

    /**
     * @return string Request time.
     */
    public function getTime(): string {
        return $this->internalRequest->httpDate;
    }
}
