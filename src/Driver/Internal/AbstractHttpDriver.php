<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpErrorException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;

/** @internal */
abstract class AbstractHttpDriver implements HttpDriver
{
    private static ?TimeoutQueue $timeoutQueue = null;
    private static ?ErrorHandler $defaultErrorHandler = null;

    final protected static function getTimeoutQueue(): TimeoutQueue
    {
        return self::$timeoutQueue ??= new TimeoutQueue();
    }

    private static function getDefaultErrorHandler(): ErrorHandler
    {
        return self::$defaultErrorHandler ??= new DefaultErrorHandler();
    }

    private int $pendingRequestHandlerCount = 0;
    private int $pendingResponseCount = 0;

    protected function __construct(
        protected readonly RequestHandler $requestHandler,
        protected readonly ErrorHandler $errorHandler,
        protected readonly LoggerInterface $logger,
    ) {
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
            $response = $this->handleError($exception->getStatus(), $exception->getReason(), $request);
        } catch (\Throwable $exception) {
            $response = $this->handleInternalServerError($request, $exception);
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
     * Write the given response to the client using the write callback provided to `setup()`.
     */
    abstract protected function write(Request $request, Response $response): void;

    /**
     * Used if an exception is thrown from a request handler.
     */
    private function handleInternalServerError(Request $request, \Throwable $exception): Response
    {
        $status = HttpStatus::INTERNAL_SERVER_ERROR;

        $client = $request->getClient();
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $protocolVersion = $request->getProtocolVersion();
        $local = $client->getLocalAddress()->toString();
        $remote = $client->getRemoteAddress()->toString();

        $this->logger->error(
            \sprintf(
                "Unexpected %s with message '%s' thrown from %s:%d when handling request: %s %s HTTP/%s %s on %s",
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $method,
                $uri,
                $protocolVersion,
                $remote,
                $local,
            ),
            [
                'exception' => $exception,
                'method' => $request,
                'uri' => $uri,
                'protocolVersion' => $protocolVersion,
                'local' => $local,
                'remote' => $remote,
            ],
        );

        return $this->handleError($status, null, $request);
    }

    private function handleError(int $status, ?string $reason, Request $request): Response
    {
        try {
            return $this->errorHandler->handleError($status, $reason, $request);
        } catch (\Throwable $exception) {
            // If the error handler throws, fallback to returning the default error page.
            $this->logger->error(
                \sprintf(
                    "Unexpected %s thrown from %s::handleError(), falling back to default error handler.",
                    $exception::class,
                    $this->errorHandler::class,
                ),
                ['exception' => $exception],
            );

            // The default error handler will never throw, otherwise there's a bug
            return self::getDefaultErrorHandler()->handleError($status, null, $request);
        }
    }
}
