<?php declare(strict_types=1);

namespace Amp\Http\Server\Test\Driver;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
use PHPUnit\Framework\MockObject\MockObject;

abstract class HttpDriverTest extends AsyncTestCase
{
    protected function createClientMock(): Client&MockObject
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

    public function createErrorHandlerMock(int $expect): ErrorHandler&MockObject
    {
        $errorHandler = $this->createMock(ErrorHandler::class);
        $errorHandler->expects($this->exactly($expect))
            ->method('handleError')
            ->willReturnCallback(static fn (int $status) => new Response($status));

        return $errorHandler;
    }
}
