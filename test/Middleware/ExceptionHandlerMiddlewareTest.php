<?php declare(strict_types=1);

namespace Amp\Http\Server\Test\Middleware;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\ExceptionHandler;
use Amp\Http\Server\HttpErrorException;
use Amp\Http\Server\Middleware\ExceptionHandlerMiddleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use League\Uri\Http;

class ExceptionHandlerMiddlewareTest extends AsyncTestCase
{
    private function setupAndInvokeMiddleware(ExceptionHandler $exceptionHandler, \Throwable $exception): Response
    {
        $request = new Request($this->createMock(Client::class), 'GET', Http::createFromString('/'));

        $requestHandler = $this->createMock(RequestHandler::class);
        $requestHandler->expects(self::once())
            ->method('handleRequest')
            ->with($request)
            ->willThrowException($exception);

        $middleware = new ExceptionHandlerMiddleware($exceptionHandler);

        return $middleware->handleRequest($request, $requestHandler);
    }

    public function testUncaughtException(): void
    {
        $exception = new TestException();

        $exceptionHandler = $this->createMock(ExceptionHandler::class);
        $exceptionHandler->expects(self::once())
            ->method('handleException')
            ->with(self::isInstanceOf(Request::class), $exception)
            ->willReturn(new Response(HttpStatus::INTERNAL_SERVER_ERROR));

        $this->setupAndInvokeMiddleware($exceptionHandler, $exception);
    }

    public function testHttpErrorException(): void
    {
        $exception = new HttpErrorException(HttpStatus::BAD_REQUEST);

        $exceptionHandler = $this->createMock(ExceptionHandler::class);
        $exceptionHandler->expects(self::never())
            ->method('handleException');

        $this->expectExceptionObject($exception);

        $this->setupAndInvokeMiddleware($exceptionHandler, $exception);
    }
}
