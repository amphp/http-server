<?php

namespace Aerys\Websocket;

use Aerys\Response;

class Handshake implements Response {
    const ACCEPT_CONCAT = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

    private $response;
    private $acceptKey;
    private $status = 101;
    private $isStarted = false;

    /**
     * @param \Aerys\Response $response The server Response to wrap for the handshake
     * @param string $acceptKey The client request's SEC-WEBSOCKET-KEY header value
     */
    public function __construct(Response $response, string $acceptKey) {
        $this->response = $response;
        $this->acceptKey = $acceptKey;

        $response->setStatus($this->status);
    }

    /**
     * {@inheritDoc}
     */
    public function setStatus(int $code): Response {
        if (!($code === 101 || $code >= 300)) {
            throw new \DomainException(
                "Invalid websocket handshake status ({$code}); 101 or 300-599 required"
            );
        }
        $this->response->setStatus($code);
        $this->status = $code;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setReason(string $phrase): Response {
        $this->response->setReason($phrase);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function addHeader(string $field, string $value): Response {
        $this->response->addHeader($field, $value);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setHeader(string $field, string $value): Response {
        $this->response->setHeader($field, $value);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function stream(string $partialBodyChunk): \Amp\Promise {
        if ($this->status === 101) {
            throw new \DomainException(
                "Cannot stream(); entity body content disallowed for Switching Protocols Response"
            );
        }
        if (!$this->isStarted) {
            $this->handshake();
        }
        $this->isStarted = true;
        return $this->response->stream($partialBodyChunk);
    }

    /**
     * {@inheritDoc}
     */
    public function flush() {
        if ($this->status === 101) {
            throw new \DomainException(
                "Cannot flush(); entity body content disallowed for Switching Protocols Response"
            );
        }
        // We don't assign websocket headers in flush() because calling
        // this method before Response output starts is an error and will
        // throw when invoked on the wrapped Response.
        $this->response->flush();
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function end(string $finalBodyChunk = null) {
        if ($this->status === 101 && isset($finalBodyChunk)) {
            throw new \DomainException(
                "Cannot end() with body data; entity body content disallowed for Switching Protocols Response"
            );
        }
        if (!$this->isStarted) {
            $this->handshake();
        }
        $this->isStarted = true;
        $this->response->end($finalBodyChunk);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setCookie(string $name, string $value, array $flags = []): Response {
        $this->response->setCookie($name, $value, $flags);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function push(string $url, array $headers = null): Response {
        return $this->response->push($url, $headers);
    }

    /**
     * {@inheritDoc}
     */
    public function state(): int {
        return $this->response->state();
    }

    private function handshake() {
        if ($this->status === 101) {
            $concatKeyStr = $this->acceptKey . self::ACCEPT_CONCAT;
            $secWebSocketAccept = base64_encode(sha1($concatKeyStr, true));
            $this->response->setHeader("Upgrade", "websocket");
            $this->response->setHeader("Connection", "upgrade");
            $this->response->setHeader("Sec-WebSocket-Accept", $secWebSocketAccept);
        }
    }
}
