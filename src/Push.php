<?php

namespace Amp\Http\Server;

use Amp\Http\InvalidHeaderException;
use Amp\Http\Message;
use Psr\Http\Message\UriInterface as PsrUri;

final class Push extends Message
{
    /** @var PsrUri */
    private $uri;

    /**
     * @param PsrUri            $uri
     * @param string[]|string[] $headers
     *
     * @throws InvalidHeaderException If given headers contain and invalid header name or value.
     * @throws \Error If the given headers have a colon-prefixed header or a Host header.
     */
    public function __construct(PsrUri $uri, array $headers = [])
    {
        $this->setHeaders($headers);
        $this->uri = $uri;
    }

    protected function setHeader(string $name, $value): void
    {
        if (($name[0] ?? ":") === ":" || !\strncasecmp("host", $name, 4)) {
            throw new \Error("Pushed headers must not contain colon-prefixed headers or a Host header");
        }

        parent::setHeader($name, $value);
    }

    public function getUri(): PsrUri
    {
        return $this->uri;
    }
}
