<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamChain;
use Amp\ByteStream\WritableStream;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\UpgradedSocket;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

    private static ErrorHandler $defaultErrorHandler;

    private static function getDefaultErrorHandler(): ErrorHandler
    {
        return self::$defaultErrorHandler ??= new DefaultErrorHandler(new NullLogger());
    }

    private int $pendingRequestHandlerCount = 0;
    private int $pendingResponseCount = 0;

    public function __construct(
        private RequestHandler $requestHandler,
        private ErrorHandler $errorHandler,
        private LoggerInterface $logger,
        private Options $options,
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
            $method = $request->getMethod();

            if (!\in_array($method, $this->options->getAllowedMethods(), true)) {
                $response = $this->handleInvalidMethod(
                    isset(self::KNOWN_METHODS[$method]) ? Status::METHOD_NOT_ALLOWED : Status::NOT_IMPLEMENTED
                );
            } elseif ($method === "OPTIONS" && $request->getUri()->getPath() === "") {
                $response = new Response(Status::NO_CONTENT, [
                    "allow" => \implode(", ", $this->options->getAllowedMethods()),
                ]);
            } else {
                $response = $this->requestHandler->handleRequest($request);
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof ClientException) {
                throw $exception;
            }

            $response = $this->handleInternalServerError($request);
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

    protected function getRequestHandler(): RequestHandler
    {
        return $this->requestHandler;
    }

    protected function getErrorHandler(): ErrorHandler
    {
        return $this->errorHandler;
    }

    protected function getOptions(): Options
    {
        return $this->options;
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    private function handleInvalidMethod(int $status): Response
    {
        $response = $this->errorHandler->handleError($status);
        $response->setHeader("allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    /**
     * Used if an exception is thrown from a request handler.
     */
    private function handleInternalServerError(Request $request): Response
    {
        $status = Status::INTERNAL_SERVER_ERROR;

        try {
            return $this->errorHandler->handleError($status, null, $request);
        } catch (\Throwable $exception) {
            $exceptionClass = $exception::class;
            $errorHandlerClass = $this->errorHandler::class;

            // If the error handler throws, fallback to returning the default error page.
            $this->logger->error(
                "Unexpected {$exceptionClass} thrown from {$errorHandlerClass}::handleError(), falling back to default error handler.",
                ['exception' => $exception]
            );

            // The default error handler will never throw, otherwise there's a bug
            return self::getDefaultErrorHandler()->handleError($status, null, $request);
        }
    }
}
