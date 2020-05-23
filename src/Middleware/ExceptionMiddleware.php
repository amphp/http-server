<?php

namespace Amp\Http\Server\Middleware;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\ServerObserver;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

final class ExceptionMiddleware implements Middleware, ServerObserver
{
    /** @var bool */
    private $debug = false;

    /** @var bool */
    private $requestLogContext = false;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Amp\Http\Server\ErrorHandler */
    private $errorHandler;

    public function handleRequest(Request $request, RequestHandler $requestHandler): Promise
    {
        static $internalErrorHtml;

        if ($internalErrorHtml === null) {
            $internalErrorHtml = \file_get_contents(\dirname(__DIR__, 2) . "/resources/internal-server-error.html");
        }

        return call(function () use ($request, $requestHandler, $internalErrorHtml) {
            try {
                return yield $requestHandler->handleRequest($request);
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

                return yield $this->errorHandler->handleError($status, null, $request);
            }
        });
    }

    public function onStart(HttpServer $server): Promise
    {
        $this->debug = $server->getOptions()->isInDebugMode();
        $this->requestLogContext = $server->getOptions()->isRequestLogContextEnabled();
        $this->logger = $server->getLogger();
        $this->errorHandler = $server->getErrorHandler();
        return new Success;
    }

    public function onStop(HttpServer $server): Promise
    {
        return new Success;
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
