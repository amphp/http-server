<?php

namespace Aerys;

class StandardResponse implements Response {
    private $filter;
    private $writer;
    private $client;
    private $status = 200;
    private $reason = "";
    private $headers = "";
    private $cookies = [];
    private $state = self::NONE;

    /**
     * @param \Aerys\Filter $filter
     * @param \Generator $writer
     * @param \Aerys\Rfc7230Client $client
     */
    public function __construct(Filter $filter, \Generator $writer, Rfc7230Client $client) {
        $this->filter = $filter;
        $this->writer = $writer;
        $this->client = $client;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function setStatus(int $code): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot set status code; output already started"
            );
        }
        assert(($code >= 100 && $code <= 599), "Invalid HTTP status code [100-599] expected");
        $this->status = $code;

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function setReason(string $phrase): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot set reason phrase; output already started"
            );
        }
        assert(isValidReasonPhrase($phrase), "Invalid reason phrase: {$phrase}");
        $this->reason = $phrase;

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function addHeader(string $field, string $value): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot add header; output already started"
            );
        }
        assert(isValidHeaderField($field), "Invalid header field: {$field}");
        assert(isValidHeaderValue($value), "Invalid header value: {$value}");
        $this->headers = addHeader($this->headers, $field, $value);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function setHeader(string $field, string $value): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot set header; output already started"
            );
        }
        assert(isValidHeaderField($field), "Invalid header field: {$field}");
        assert(isValidHeaderValue($value), "Invalid header value: {$value}");
        $this->headers = setHeader($this->headers, $field, $value);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function setCookie(string $name, string $value, array $flags = []): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot set header; output already started"
            );
        }

        // @TODO assert() valid $name / $value / $flags
        $this->cookies[$name] = [$value, $flags];

        return $this;
    }

    /**
     * Send the specified response entity body
     *
     * @param string $body The full response entity body
     * @throws \LogicException If response output already started
     * @throws \Aerys\ClientException If the client has already disconnected
     * @return self
     */
    public function send(string $body): Response {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot send: response already sent"
            );
        } elseif ($this->state & self::STREAMING) {
            throw new \LogicException(
                "Cannot send: response already streaming"
            );
        } else {
            return $this->end($body);
        }
    }

    /**
     * Stream partial entity body data
     *
     * If response output has not yet started headers will also be sent
     * when this method is invoked.
     *
     * @param string $partialBody
     * @throws \LogicException If response output already complete
     * @return self
     */
    public function stream(string $partialBody): Response {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot stream: response already sent"
            );
        }

        if ($this->state & self::STARTED) {
            $toFilter = $partialBody;
        } else {
            $this->setCookies();

            // A * (as opposed to a numeric length) indicates "streaming entity content"
            $headers = setHeader($this->headers, "__Aerys-Entity-Length", "*");
            $headers = trim($headers);
            $toFilter = "{proto} {$this->status} {$this->reason}\r\n{$headers}\r\n\r\n{$partialBody}";
        }

        $filtered = $this->filter->sink($toFilter);
        if ($filtered !== "") {
            $this->writer->send($filtered);
        }

        // Don't update the state until *AFTER* the filter operation so that if
        // it throws we can handle FilterException appropriately in the server.
        $this->state = self::STREAMING|self::STARTED;

        return $this;
    }

    /**
     * Request that any buffered data be flushed to the client
     *
     * This method only makes sense when streaming output via Response::stream().
     * Invoking it before calling stream() or after send()/end() is a logic error.
     *
     * @throws \LogicException If invoked before stream() or after send()/end()
     * @return self
     */
    public function flush(): Response {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot flush: response already sent"
            );
        } elseif ($this->state & self::STARTED) {
            $filtered = $this->filter->flush();
            if ($filtered !== "") {
                $this->writer->send($filtered);
            }
        } else {
            throw new \LogicException(
                "Cannot flush: response output not started"
            );
        }

        return $this;
    }

    /**
     * Signify the end of streaming response output
     *
     * User applications are NOT required to call Response::end() as the server
     * will handle this automatically as needed.
     *
     * Passing the optional $finalBody is equivalent to the following:
     *
     *     $response->stream($finalBody);
     *     $response->end();
     *
     * @param string $finalBody Optional final body data to send
     * @return self
     */
    public function end(string $finalBody = null): Response {
        if ($this->state & self::ENDED) {
            if (isset($finalBody)) {
                throw new \LogicException(
                    "Cannot send body data: response output already ended"
                );
            }
            return $this;
        }

        if ($this->state & self::STARTED) {
            $toFilter = $finalBody;
        } else {
            $this->setCookies();

            // An @ (as opposed to a numeric length) indicates "no entity content"
            $entityValue = isset($finalBody) ? strlen($finalBody) : "@";
            $headers = setHeader($this->headers, "__Aerys-Entity-Length", $entityValue);
            $headers = trim($headers);
            $toFilter = "{proto} {$this->status} {$this->reason}\r\n{$headers}\r\n\r\n{$finalBody}";
        }

        $filtered = $this->filter->end($toFilter);
        if ($filtered !== "") {
            $this->writer->send($filtered);
        }
        $this->writer->send(null);

        // Update the state *AFTER* the filter operation so that if it throws
        // we can handle things appropriately in the server.
        $this->state = self::ENDED|self::STARTED;

        return $this;
    }

    private function setCookies() {
        foreach ($this->cookies as $name => list($value, $flags)) {
            $cookie = "$name=$value";

            foreach ($flags as $name => $value) {
                if (\is_int($name)) {
                    $cookie .= "; $value";
                } else {
                    $cookie .= "; $name=$value";
                }
            }

            $this->headers = addHeader($this->headers, "Set-Cookie", $cookie);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function onUpgrade(callable $onUpgrade): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot assign onUpgrade callback; output already started"
            );
        }
        $this->client->onUpgrade = $onUpgrade;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function state(): int {
        return $this->state;
    }
}
