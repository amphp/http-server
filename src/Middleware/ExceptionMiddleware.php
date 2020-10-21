<?php

namespace Amp\Http\Server\Middleware;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\ServerObserver;
use Amp\Http\Status;
use Psr\Log\LoggerInterface as PsrLogger;

final class ExceptionMiddleware implements Middleware, ServerObserver
{
    private bool $debug = false;

    private bool $requestLogContext = false;

    private PsrLogger $logger;

    private ErrorHandler $errorHandler;

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        static $internalErrorHtml;

        if ($internalErrorHtml === null) {
            $internalErrorHtml = \file_get_contents(\dirname(__DIR__, 2) . "/resources/internal-server-error.html");
        }

        try {
            return $requestHandler->handleRequest($request);
        } catch (ClientException $exception) {
            throw $exception; // Ignore ClientExceptions.
        } catch (\Throwable $exception) {
            $status = Status::INTERNAL_SERVER_ERROR;

            $errorType = \get_class($exception);

            // Return an HTML page with the exception in debug mode.
            if ($this->debug) {
                $this->logger->error(
                    "Unexpected {$errorType} thrown from RequestHandler::handleRequest(), falling back to stack trace response, because debug mode is enabled.",
                    $this->createLogContext($exception, $request)
                );

                $html = \str_replace(
                    ["{uri}", "{class}", "{message}", "{file}", "{line}", "{trace}"],
                    \array_map("htmlspecialchars", [
                        $request->getUri(),
                        \get_class($exception),
                        $exception->getMessage(),
                        $exception->getFile(),
                        $exception->getLine(),
                        $exception->getTraceAsString()
                    ]),
                    $internalErrorHtml
                );

                return new Response($status, [
                    "content-type" => "text/html; charset=utf-8",
                ], $html);
            }

            $this->logger->error(
                "Unexpected {$errorType} thrown from RequestHandler::handleRequest(), falling back to error handler.",
                $this->createLogContext($exception, $request)
            );

            return $this->errorHandler->handleError($status, null, $request);
        }
    }

    public function onStart(HttpServer $server): void
    {
        $this->debug = $server->getOptions()->isInDebugMode();
        $this->requestLogContext = $server->getOptions()->isRequestLogContextEnabled();
        $this->logger = $server->getLogger();
        $this->errorHandler = $server->getErrorHandler();
    }

    public function onStop(HttpServer $server): void
    {
    }

    private function createLogContext(\Throwable $exception, Request $request): array
    {
        $logContext = ['exception' => $exception];
        if ($this->requestLogContext) {
            $logContext['request'] = $request;
        }

        return $logContext;
    }
}
