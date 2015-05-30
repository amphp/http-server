<?php

namespace Aerys;

class StandardRequest implements Request {
    private $internalRequest;
    private $queryVars;

    /**
     * @param \Aerys\InternalRequest $internalRequest
     */
    public function __construct(InternalRequest $internalRequest) {
        $this->internalRequest = $internalRequest;
    }

    /**
     * {@inheritdoc}
     */
    public function getMethod(): string {
        return $this->internalRequest->method;
    }

    /**
     * {@inheritdoc}
     */
    public function getUri(): string {
        return $this->internalRequest->uri;
    }

    /**
     * {@inheritdoc}
     */
    public function getProtocolVersion(): string {
        return $this->internalRequest->protocol;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader(string $field) {
        $field = strtoupper($field);
        return isset($this->internalRequest->headers[$field])
            ? $this->internalRequest->headers[$field][0]
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderArray(string $field): array {
        return $this->internalRequest->headers[strtoupper($field)] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllHeaders(): array {
        return $this->internalRequest->headers;
    }

    /**
     * {@inheritdoc}
     */
    public function getBody(): Body {
        return $this->internalRequest->body;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueryVars(): array {
        if (isset($this->queryVars)) {
            return $this->queryVars;
        }

        if (empty($this->internalRequest->uriQuery)) {
            return $this->queryVars = [];
        }

        parse_str($this->internalRequest->uriQuery, $this->queryVars);

        return $this->queryVars;
    }

    /**
     * {@inheritdoc}
     */
    public function getCookie(string $name) {
        return $this->internalRequest->cookies[$name] ?? (
            isset($this->internalRequest->cookies) ? null : (
                ($this->internalRequest->cookies = array_merge(...array_map('\Aerys\parseCookie', $this->internalRequest->headers["COOKIE"] ?? [""])))[$name] ?? null
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getTrace(): string {
        return $this->internalRequest->trace;
    }

    /**
     * {@inheritdoc}
     */
    public function getLocalVar(string $key) {
        return $this->internalRequest->locals[$key] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function setLocalVar(string $key, $value) {
        $this->internalRequest->locals[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionInfo(): array {
        return [
            "client_port" => $this->internalRequest->clientPort,
            "client_addr" => $this->internalRequest->clientAddr,
            "server_port" => $this->internalRequest->serverPort,
            "server_addr" => $this->internalRequest->serverAddr,
            "is_encrypted"=> $this->internalRequest->isEncrypted,
            "crypto_info" => $this->internalRequest->cryptoInfo,
        ];
    }
}
