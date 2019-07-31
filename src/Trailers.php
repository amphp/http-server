<?php

namespace Amp\Http\Server;

use Amp\Http\Message;

final class Trailers extends Message
{
    /** @see https://tools.ietf.org/html/rfc7230#section-4.1.2 */
    public const DISALLOWED_TRAILERS = [
        "authorization" => 1,
        "content-encoding" => 1,
        "content-length" => 1,
        "content-range" => 1,
        "content-type" => 1,
        "cookie" => 1,
        "expect" => 1,
        "host" => 1,
        "pragma" => 1,
        "proxy-authenticate" => 1,
        "proxy-authorization" => 1,
        "range" => 1,
        "te" => 1,
        "trailer" => 1,
        "transfer-encoding" => 1,
        "www-authenticate" => 1,
    ];

    /**
     * @param string[][] $headers
     */
    public function __construct(array $headers)
    {
        if (!empty($headers)) {
            $this->setHeaders($headers);
        }
    }

    public function setHeaders(array $headers): void
    {
        parent::setHeaders($headers);
    }

    public function setHeader(string $name, $value): void
    {
        if (isset(self::DISALLOWED_TRAILERS[\strtolower($name)])) {
            throw new \Error(\sprintf("Field %s is not allowed in trailers", $name));
        }

        parent::setHeader($name, $value);
    }

    public function addHeader(string $name, $value): void
    {
        if (isset(self::DISALLOWED_TRAILERS[\strtolower($name)])) {
            throw new \Error(\sprintf("Field %s is not allowed in trailers", $name));
        }

        parent::addHeader($name, $value);
    }

    public function removeHeader(string $name): void
    {
        parent::removeHeader($name);
    }
}
