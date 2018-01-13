<?php

namespace Aerys;

use Amp\Promise;
use Amp\Success;

/**
 * ErrorHandler instance used by default if none is given.
 */
class DefaultErrorHandler implements ErrorHandler {
    /** {@inheritdoc} */
    public function handle(int $statusCode, string $reason, Request $request = null): Promise {
        return new Success(new Response\HtmlResponse(makeGenericBody($statusCode), [], $statusCode, $reason));
    }
}
