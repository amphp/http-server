<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultExceptionHandler;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\ExceptionHandler;
use Amp\Http\Server\HttpErrorException;
use Amp\Http\Server\Middleware\ExceptionHandlerMiddleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface as PsrLogger;

/** @internal */
abstract class AbstractHttpDriver implements HttpDriver
{
    protected const HOST_HEADER_REGEX = /** @lang RegExp */ '#^([A-Z\d._\-]+|\[[\dA-F:]+])(?::([1-9]\d*))?$#i';

    private static ?TimeoutQueue $timeoutQueue = null;

    final protected static function getTimeoutQueue(): TimeoutQueue
    {
        return self::$timeoutQueue ??= new TimeoutQueue();
    }

    private readonly DefaultExceptionHandler $exceptionHandler;

    private int $pendingRequestHandlerCount = 0;
    private int $pendingResponseCount = 0;

    protected readonly ErrorHandler $errorHandler;

    protected function __construct(
        protected readonly RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        protected readonly PsrLogger $logger,
    ) {
        $this->errorHandler = new HttpDriverErrorHandler($errorHandler, $this->logger);
        $this->exceptionHandler = new DefaultExceptionHandler($this->errorHandler, $this->logger);
    }

    /**
     * Respond to a parsed request.
     */
    final protected function handleRequest(Request $request): void
    {
        $clientRequest = $request;
        $request = clone $request;

        $this->pendingRequestHandlerCount++;
        $this->pendingResponseCount++;

        try {
            $response = $this->requestHandler->handleRequest($request);
        } catch (ClientException $exception) {
            throw $exception;
        } catch (HttpErrorException $exception) {
            $response = $this->errorHandler->handleError($exception->getStatus(), $exception->getReason(), $request);
        } catch (\Throwable $exception) {
            /**
             * This catch is not designed to be a general-purpose exception handler, rather a last-resort to write to
             * the logger if the application has failed to handle an exception thrown from a {@see RequestHandler}.
             * Instead of relying on this handler, use {@see ExceptionHandler} and {@see ExceptionHandlerMiddleware}.
             */
            $response = $this->exceptionHandler->handleException($request, $exception);
        } finally {
            $this->pendingRequestHandlerCount--;
        }

        /** @psalm-suppress RedundantCondition */
        \assert($this->logger->debug(\sprintf(
            '"%s %s" %d "%s" HTTP/%s @ %s #%d',
            $clientRequest->getMethod(),
            (string) $clientRequest->getUri(),
            $response->getStatus(),
            $response->getReason(),
            $clientRequest->getProtocolVersion(),
            $clientRequest->getClient()->getRemoteAddress()->toString(),
            $clientRequest->getClient()->getId(),
        )) || true);

        $this->write($clientRequest, $response);

        $this->pendingResponseCount--;
    }

    /**
     * Write the given response to the client.
     */
    abstract protected function write(Request $request, Response $response): void;
}
