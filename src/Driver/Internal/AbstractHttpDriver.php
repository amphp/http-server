<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Psr\Log\LoggerInterface;

/** @internal */
abstract class AbstractHttpDriver implements HttpDriver
{
    /**
     * HTTP methods that are *known*.
     *
     * Requests for methods not defined here or within Options should result in a 501 (not implemented) response.
     */
    private const KNOWN_METHODS = [
        "GET" => true,
        "HEAD" => true,
        "POST" => true,
        "PUT" => true,
        "PATCH" => true,
        "DELETE" => true,
        "OPTIONS" => true,
        "TRACE" => true,
        "CONNECT" => true,
    ];

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
        protected readonly array $allowedMethods = HttpDriver::DEFAULT_ALLOWED_METHODS,
    ) {
    }

    /**
     * Respond to a parsed request.
     */
    final protected function handleRequest(Request $request): void
    {
        /** @psalm-suppress RedundantCondition */
        \assert($this->logger->debug(\sprintf(
            "%s %s HTTP/%s @ %s #%d",
            $request->getMethod(),
            (string) $request->getUri(),
            $request->getProtocolVersion(),
            $request->getClient()->getRemoteAddress()->toString(),
            $request->getClient()->getId(),
        )) || true);

        $clientRequest = $request;
        $request = clone $request;

        $this->pendingRequestHandlerCount++;
        $this->pendingResponseCount++;

        try {
            $method = $request->getMethod();

            if (!\in_array($method, $this->allowedMethods, true)) {
                $response = $this->handleInvalidMethod(
                    isset(self::KNOWN_METHODS[$method]) ? Status::METHOD_NOT_ALLOWED : Status::NOT_IMPLEMENTED
                );
            } elseif ($method === "OPTIONS" && $request->getUri()->getPath() === "") {
                $response = new Response(Status::NO_CONTENT, [
                    "allow" => \implode(", ", $this->allowedMethods),
                ]);
            } else {
                $response = $this->requestHandler->handleRequest($request);
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof ClientException) {
                throw $exception;
            }

            $response = $this->handleInternalServerError($request, $exception);
        } finally {
            $this->pendingRequestHandlerCount--;
        }

        $this->write($clientRequest, $response);

        $this->pendingResponseCount--;
    }

    /**
     * Write the given response to the client using the write callback provided to `setup()`.
     */
    abstract protected function write(Request $request, Response $response): void;

    private function handleInvalidMethod(int $status): Response
    {
        $response = $this->errorHandler->handleError($status);
        $response->setHeader("allow", \implode(", ", $this->allowedMethods));
        return $response;
    }

    /**
     * Used if an exception is thrown from a request handler.
     */
    private function handleInternalServerError(Request $request, \Throwable $exception): Response
    {
        $status = Status::INTERNAL_SERVER_ERROR;

        $this->logger->error(
            \sprintf(
                "Unexpected %s thrown from %s::handleRequest(), falling back to error handler.",
                $exception::class,
                $this->requestHandler::class,
            ),
            ['exception' => $exception],
        );

        try {
            return $this->errorHandler->handleError($status, null, $request);
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
