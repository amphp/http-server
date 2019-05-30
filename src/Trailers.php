<?php

namespace Amp\Http\Server;

use Amp\Http\Message;

final class Trailers extends Message
{
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
        parent::setHeader($name, $value);
    }

    public function addHeader(string $name, $value): void
    {
        parent::addHeader($name, $value);
    }

    public function removeHeader(string $name): void
    {
        parent::removeHeader($name);
    }
}
