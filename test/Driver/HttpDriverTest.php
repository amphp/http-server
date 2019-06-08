<?php

namespace Amp\Http\Server\Test\Driver;

use Amp\Http\Server\Driver\Client;
use Amp\PHPUnit\TestCase;
use Amp\Socket\SocketAddress;

abstract class HttpDriverTest extends TestCase
{
    /**
     * @return Client|\PHPUnit\Framework\MockObject\MockObject Mocked client with empty local and remote addresses.
     */
    protected function createClientMock(): Client
    {
        $mock = $this->createMock(Client::class);

        $mock->method('getLocalAddress')
            ->willReturn(new SocketAddress(''));

        $mock->method('getRemoteAddress')
            ->willReturn(new SocketAddress(''));

        return $mock;
    }
}
