<?php

namespace Aerys;

class StandardResponse implements Response {
    private $codec;
    private $client;
    private $state = self::NONE;
    private $headers = [
        ":status" => 200,
        ":reason" => null,
    ];
    private $cookies = [];

    public function __construct(\Generator $codec, Client $client) {
        $this->codec = $codec;
        $this->client = $client;
    }

    public function __debugInfo(): array {
        return $this->headers;
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
        $this->headers[":status"] = $code;

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
        assert($this->isValidReasonPhrase($phrase), "Invalid reason phrase: {$phrase}");
        $this->headers[":reason"] = $phrase;

        return $this;
    }

    /**
     * @TODO Validate reason phrase against RFC7230 ABNF
     * @link https://tools.ietf.org/html/rfc7230#section-3.1.2
     */
    private function isValidReasonPhrase(string $phrase): bool {
        // reason-phrase  = *( HTAB / SP / VCHAR / obs-text )
        return true;
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
        assert($this->isValidHeaderField($field), "Invalid header field: {$field}");
        assert($this->isValidHeaderValue($value), "Invalid header value: {$value}");
        $this->headers[strtolower($field)][] = $value;

        return $this;
    }

    /**
     * @TODO Validate field name against RFC7230 ABNF
     * @link https://tools.ietf.org/html/rfc7230#section-3.2
     */
    private function isValidHeaderField(string $field): bool {
        // field-name     = token
        return true;
    }

    /**
     * @TODO Validate field name against RFC7230 ABNF
     * @link https://tools.ietf.org/html/rfc7230#section-3.2
     */
    private function isValidHeaderValue(string $value): bool {
        // field-value    = *( field-content / obs-fold )
        // field-content  = field-vchar [ 1*( SP / HTAB ) field-vchar ]
        // field-vchar    = VCHAR / obs-text
        //
        // obs-fold       = CRLF 1*( SP / HTAB )
        //                ; obsolete line folding
        //                ; see Section 3.2.4
        return true;
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
        assert($this->isValidHeaderField($field), "Invalid header field: {$field}");
        assert($this->isValidHeaderValue($value), "Invalid header value: {$value}");
        $this->headers[strtolower($field)] = [$value];

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
     * Stream partial entity body data
     *
     * If response output has not yet started headers will also be sent
     * when this method is invoked.
     *
     * @param string $partialBody
     * @throws \LogicException If response output already complete
     * @return \Amp\Promise to be succeeded whenever local buffers aren't full
     */
    public function stream(string $partialBody): \Amp\Promise {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot stream: response already sent"
            );
        }

        if (!($this->state & self::STARTED)) {
            $this->setCookies();
            // A * (as opposed to a numeric length) indicates "streaming entity content"
            $headers = $this->headers;
            $headers[":reason"] = $headers[":reason"] ?? HTTP_REASON[$headers[":status"]] ?? "";
            $headers[":aerys-entity-length"] = "*";
            $this->codec->send($headers);
        }

        $this->codec->send($partialBody);

        // Don't update the state until *AFTER* the codec operation so that if
        // it throws we can handle InternalFilterException appropriately in the server.
        $this->state = self::STREAMING|self::STARTED;

        if ($promisor = $this->client->bufferPromisor) {
            return $promisor->promise();
        } else {
            return $this->client->isDead & Client::CLOSED_WR ? new \Amp\Failure(new ClientException) : new \Amp\Success;
        }
    }

    /**
     * Request that any buffered data be flushed to the client
     *
     * This method only makes sense when streaming output via Response::stream().
     * Invoking it before calling stream() or after send()/end() is a logic error.
     *
     * @throws \LogicException If invoked before stream() or after send()/end()
     */
    public function flush() {
        if ($this->state & self::ENDED) {
            throw new \LogicException(
                "Cannot flush: response already sent"
            );
        } elseif ($this->state & self::STARTED) {
            $this->codec->send(false);
        } else {
            throw new \LogicException(
                "Cannot flush: response output not started"
            );
        }
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
     */
    public function end(string $finalBody = null) {
        if ($this->state & self::ENDED) {
            if (isset($finalBody)) {
                throw new \LogicException(
                    "Cannot send body data: response output already ended"
                );
            }
            return;
        }

        if (!($this->state & self::STARTED)) {
            $this->setCookies();
            // An @ (as opposed to a numeric length) indicates "no entity content"
            $entityValue = isset($finalBody) ? \strlen($finalBody) : "@";
            $headers = $this->headers;
            $headers[":reason"] = $headers[":reason"] ?? HTTP_REASON[$headers[":status"]] ?? "";
            $headers[":aerys-entity-length"] = $entityValue;
            $this->codec->send($headers);
        }

        if (isset($finalBody)) {
            $this->codec->send($finalBody);
        }
        $this->codec->send(null);

        // Update the state *AFTER* the codec operation so that if it throws
        // we can handle things appropriately in the server.
        $this->state = self::ENDED | self::STARTED;
    }

    private function setCookies() {
        foreach ($this->cookies as $name => list($value, $flags)) {
            $cookie = "$name=$value";

            $flags = array_change_key_case($flags, CASE_LOWER);
            foreach ($flags as $name => $value) {
                if (\is_int($name)) {
                    $cookie .= "; $value";
                } else {
                    $cookie .= "; $name=$value";
                }
            }

            if (isset($flags["max-age"]) && !isset($flags["expires"])) {
                $cookie .= "; expires=".date("r", time() + $flags["max-age"]);
            }

            $this->headers["set-cookie"][] = $cookie;
        }
    }

    /**
     * {@inheritDoc}
     * @throws \LogicException If output already started
     * @return self
     */
    public function push(string $url, array $headers = null): Response {
        if ($this->state & self::STARTED) {
            throw new \LogicException(
                "Cannot add push promise; output already started"
            );
        }

        \assert((function($headers) {
            foreach ($headers ?? [] as $name => $header) {
                if (\is_int($name)) {
                    if (count($header) != 2) {
                        return false;
                    }
                    list($name) = $header;
                }
                if ($name[0] == ":" || !strncasecmp("host", $name, 4)) {
                    return false;
                }
            }
            return true;
        })($headers), "Headers must not contain colon prefixed headers or a Host header. Use a full URL if necessary, the method is always GET.");

        $this->headers[":aerys-push"][$url] = $headers;

        return $this;
    }


    /**
     * {@inheritDoc}
     */
    public function state(): int {
        return $this->state;
    }
}
