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
        return $this->internalRequest->headers[strtolower($field)][0] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderArray(string $field): array {
        return $this->internalRequest->headers[strtolower($field)] ?? [];
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
        $ireq = $this->internalRequest;

        return $ireq->cookies[$name] ?? null;
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
}
