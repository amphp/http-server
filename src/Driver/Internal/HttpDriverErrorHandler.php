<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface as PsrLogger;

/** @internal */
final class HttpDriverErrorHandler implements ErrorHandler
{
    private static ?DefaultErrorHandler $defaultErrorHandler = null;

    private static function getDefaultErrorHandler(): ErrorHandler
    {
        return self::$defaultErrorHandler ??= new DefaultErrorHandler();
    }

    public function __construct(
        private readonly ErrorHandler $errorHandler,
        private readonly PsrLogger $logger,
    ) {
    }

    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response
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
