<?php

namespace Aerys;

class Rfc7230Response implements Response {
    private $filter;
    private $context;
    private $writer;
    private $state = self::NONE;

    /**
     * @param \Aerys\Filter $filter
     * @param \Aerys\Rfc7230ResponseContext $context
     * @param \Generator
     */
    public function __construct(Filter $filter, Rfc7230ResponseContext $context, \Generator $writer) {
        $this->filter = $filter;
        $this->context = $context;
        $this->writer = $writer;
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
        $this->context->status = $code;

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
        $this->context->reason = $phrase;

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
        $this->context->headers = addHeader($this->context->headers, $field, $value);

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
        $this->context->headers = setHeader($this->context->headers, $field, $value);

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
     * @throws \Aerys\ClientException If the client has already disconnected
     * @return self
     */
    public function stream(string $partialBody): Response {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot stream: response already sent"
            );
        }
        $toFilter = ($this->state & self::STARTED)
            ? $partialBody
            : $this->start($stream = true, $partialBody)
        ;
        $filtered = $this->filter->sink($toFilter);

        // Don't update the state until *AFTER* the filter operation so that if
        // it throws we can handle FilterException appropriately in the server.
        $this->state |= self::STREAMING;

        if ($filtered !== "") {
            $this->writer->send($filtered);
        }

        return $this;
    }

    /**
     * Request that any buffered data be flushed to the client
     *
     * This method only makes sense when streaming output via Response::stream().
     * Invoking it before calling stream() or after send()/end() is a logic error.
     *
     * @throws \LogicException If invoked before stream() or after send()/end()
     * @throws \Aerys\ClientException If the client has already disconnected
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
     * @throws \Aerys\ClientException If the client has already disconnected
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
        $toFilter = ($this->state & self::STARTED)
            ? $finalBody
            : $this->start($stream = false, (string) $finalBody)
        ;
        $filtered = $this->filter->end($toFilter);

        if ($filtered !== "") {
            $this->writer->send($filtered);
        }
        $this->writer->send(null);

        // Update the state *AFTER* the filter operation so that if it throws
        // we can handle things appropriately in the server.
        $this->state |= self::ENDED;

        return $this;
    }

    private function start(bool $stream, string $entity): string {
        $context  = $this->context;
        $protocol = $context->requestProtocol;
        $status   = $context->status ?? 200;
        $reason   = $context->reason;
        $headers  = $context->headers;

        if (!isset($reason) && $context->autoReasonPhrase) {
            $reason = HTTP_REASON[$status] ?? "";
        }

        if ($context->sendServerToken) {
            $headers = setHeader($headers, "Server", SERVER_TOKEN);
        }

        $headers = setHeader($headers, "Date", $context->currentHttpDate);

        if ($stream) {
            if ($protocol === "1.1") {
                $shouldClose = false;
                $headers = setHeader($headers, "Transfer-Encoding", "chunked");
            } else {
                $shouldClose = true;
                $headers = removeHeader($headers, "Content-Length");
            }
        } else {
            $shouldClose = false;
            if (isset($entity[0])) {
                $headers = setHeader($headers, "Content-Length", \strlen($entity));
            } else {
                $headers = removeHeader($headers, "Content-Length");
            }
        }

        if ($status >= 200 && ($status < 300 || $status >= 400)) {
            $type = getHeader($headers, "Content-Type") ?? $context->defaultContentType;
            if (\stripos($type, "text/") === 0 && \stripos($type, "charset=") === false) {
                $type .= "; charset={$context->defaultTextCharset}";
            }
            $headers = setHeader($headers, "Content-Type", $type);
        }

        if ($context->isServerStopping) {
            $shouldClose = true;
        }

        if ($shouldClose) {
            $headers = setHeader($headers, "Connection", "close");
        } else {
            $keepAlive = "timeout={$context->keepAliveTimeout}, max={$context->requestsRemaining}";
            $headers = setHeader($headers, "Keep-Alive", $keepAlive);
        }

        $headers = \trim($headers);

        return "HTTP/{$protocol} {$status} {$reason}\r\n{$headers}\r\n\r\n{$entity}";
    }

    /**
     * {@inheritDoc}
     */
    public function state(): int {
        return $this->state;
    }
}
