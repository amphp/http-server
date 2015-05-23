<?php

namespace Aerys\Websocket;

use Aerys\Response;
use Amp\Promisor;

class Handshake implements Response {
    const ACCEPT_CONCAT = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

    private $upgradePromisor;
    private $response;
    private $acceptKey;
    private $status = 101;
    private $isStarted = false;

    /**
     * @param \Amp\Promisor $upgradePromisor resolve as false if handshake negotiation fails
     * @param \Aerys\Response $response The server self to wrap for the handshake
     * @param string $acceptKey The client request's SEC-WEBSOCKET-KEY header value
     */
    public function __construct(Promisor $upgradePromisor, Response $response, string $acceptKey) {
        $this->upgradePromisor = $upgradePromisor;
        $this->response = $response;
        $this->acceptKey = $acceptKey;
    }

    /**
     * {@inheritDoc}
     */
    public function setStatus(int $code): self {
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
    public function setReason(string $phrase): self {
        $this->response->setReason($phrase);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function addHeader(string $field, string $value): self {
        $this->response->addHeader($field, $value);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setHeader(string $field, string $value): self {
        $this->response->setHeader($field, $value);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function send(string $body): self {
        if ($this->status === 101) {
            throw new \DomainException(
                "Cannot send(); entity body content disallowed for Switching Protocols self"
            );
        }
        if (!$this->isStarted) {
            $this->handshake();
        }
        $this->isStarted = true;
        $this->response->send($body);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function stream(string $partialBodyChunk): self {
        if ($this->status === 101) {
            throw new \DomainException(
                "Cannot stream(); entity body content disallowed for Switching Protocols self"
            );
        }
        if (!$this->isStarted) {
            $this->handshake();
        }
        $this->isStarted = true;
        $this->response->stream($partialBodyChunk);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): self {
        if ($this->status === 101) {
            throw new \DomainException(
                "Cannot flush(); entity body content disallowed for Switching Protocols self"
            );
        }
        // We don't assign websocket headers in flush() because calling
        // this method before self output starts is an error and will
        // throw when invoked on the wrapped self.
        $this->response->flush();
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function end(string $finalBodyChunk = null): self {
        if ($this->status === 101 && isset($finalBodyChunk)) {
            throw new \DomainException(
                "Cannot end() with body data; entity body content disallowed for Switching Protocols self"
            );
        }
        if (!$this->isStarted) {
            $this->handshake();
        }
        $this->isStarted = true;
        $this->response->stream($finalBodyChunk);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function onUpgrade(callable $onUpgrade): self {
        throw new \DomainException(
            __METHOD__ . " disallowed; protocol upgrades handled by the websocket endpoint"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function state(): int {
        return $this->response->state();
    }

    private function handshake() {
        if ($this->status !== 101) {
            $this->upgradePromisor->succeed($wasUpgraded = false);
            return;
        }

        $concatKeyStr = $this->acceptKey . self::ACCEPT_CONCAT;
        $secWebSocketAccept = base64_encode(sha1($concatKeyStr, true));
        $this->response->setHeader("Upgrade", "websocket");
        $this->response->setHeader("Connection", "upgrade");
        $this->response->setHeader("Sec-WebSocket-Accept", $secWebSocketAccept);
    }
}
