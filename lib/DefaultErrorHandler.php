<?php

namespace Aerys;

use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;

/**
 * ErrorHandler instance used by default if none is given.
 */
class DefaultErrorHandler implements ErrorHandler {
    /** @var string[] */
    private $cache = [];

    /** {@inheritdoc} */
    public function handle(int $statusCode, string $reason = null, Request $request = null): Promise {
        if (!isset($this->cache[$statusCode])) {
            $this->cache[$statusCode] = \str_replace(
                ["{code}", "{reason}"],
                // Using standard reason in HTML for caching purposes.
                \array_map("htmlspecialchars", [$statusCode, Status::getReason($statusCode)]),
                DEFAULT_ERROR_HTML
            );
        }

        return new Success(new Response\HtmlResponse($this->cache[$statusCode], [], $statusCode, $reason));
    }
}
