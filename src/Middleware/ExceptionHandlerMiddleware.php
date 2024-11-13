<?php declare(strict_types=1);

namespace Amp\Http\Server\Middleware;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\ExceptionHandler;
use Amp\Http\Server\HttpErrorException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * This middleware catches exceptions from the wrapped {@see RequestHandler}, delegating handling of the exception to
 * the provided instance of {@see ExceptionHandler}. Generally it is recommended that this middleware be first in the
 * middleware stack so it is able to catch any exception from another middleware or request handler.
 */
final class ExceptionHandlerMiddleware implements Middleware
{
    public function __construct(private readonly ExceptionHandler $exceptionHandler)
    {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        try {
            return $requestHandler->handleRequest($request);
        } catch (ClientException|HttpErrorException $exception) {
            // Rethrow our special client exception or HTTP error exception. These exceptions have special meaning
            // to the HTTP driver, so will be handled differently from other uncaught exceptions from the request
            // handler.
            throw $exception;
        } catch (\Throwable $exception) {
            return $this->exceptionHandler->handleException($request, $exception);
        }
    }
}
