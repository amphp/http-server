<?php

namespace Aerys;

class StandardRequest implements Request {
    private $internalRequest;
    private $queryParams;
    private $body;

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
    public function getBody(int $bodySize = -1): Body {
        $ireq = $this->internalRequest;
        if ($bodySize > -1) {
            if ($bodySize > ($ireq->maxBodySize ?? $ireq->client->options->maxBodySize)) {
                $ireq->maxBodySize = $bodySize;
                $ireq->client->httpDriver->upgradeBodySize($this->internalRequest);
            }
        }
        
        if ($ireq->body != $this->body) {
            $this->body = $ireq->body->when(function ($e, $data) {
                if ($e instanceof ClientSizeException) {
                    $ireq = $this->internalRequest;
                    $bodyPromisor = $ireq->client->bodyPromisors[$ireq->streamId];
                    $ireq->body = new Body($bodyPromisor->promise());
                    $bodyPromisor->update($data);
                }
            });
        }
        return $ireq->body;
    }

    /**
     * {@inheritdoc}
     */
    public function getParam(string $name) {
        return ($this->queryParams ?? $this->queryParams = $this->parseParams())[$name][0] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function getParamArray(string $name): array {
        return ($this->queryParams ?? $this->queryParams = $this->parseParams())[$name] ?? [];
    }

    /**
     * {@inheritdoc}
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
            $this->queryParams[rawurldecode($pair[0])][] = rawurldecode($pair[1] ?? "");
        }

        return $this->queryParams;
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

    /**
     * {@inheritdoc}
     */
    public function getOption(string $option) {
        return $this->internalRequest->client->options->{$option};
    }

}
