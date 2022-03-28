<?php

namespace Amp\Http\Server\Test\Driver;

use Amp\Http\Server\Driver\Client;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;

abstract class HttpDriverTest extends AsyncTestCase
{
    /**
     * @return Client|\PHPUnit\Framework\MockObject\MockObject Mocked client with empty local and remote addresses.
     */
    protected function createClientMock(): Client
    {
        $mock = $this->createMock(Client::class);

        $mock->method('getLocalAddress')
            ->willReturn(new InternetAddress('127.0.0.1', 80));

        $mock->method('getRemoteAddress')
            ->willReturn(new InternetAddress('127.0.0.1', 12345));

        return $mock;
    }
}
