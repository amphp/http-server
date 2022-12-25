<?php declare(strict_types=1);

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
        static $id = 0;

        $mock = $this->createMock(Client::class);

        $mock->method('getId')
            ->willReturn(++$id);

        $mock->method('getLocalAddress')
            ->willReturn(new InternetAddress('127.0.0.1', 80));

        $mock->method('getRemoteAddress')
            ->willReturn(new InternetAddress('127.0.0.1', 12345));

        return $mock;
    }
}
