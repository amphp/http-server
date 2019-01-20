<?php

namespace Amp\Http\Server;

use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;

/**
 * ErrorHandler instance used by default if none is given.
 */
final class DefaultErrorHandler implements ErrorHandler
{
    /** @var string[] */
    private $cache = [];

    /** {@inheritdoc} */
    public function handleError(int $statusCode, string $reason = null, Request $request = null): Promise
    {
        static $errorHtml;

        if ($errorHtml === null) {
            $errorHtml = \file_get_contents(\dirname(__DIR__) . "/resources/error.html");
        }

        if (!isset($this->cache[$statusCode])) {
            $this->cache[$statusCode] = \str_replace(
                ["{code}", "{reason}"],
                // Using standard reason in HTML for caching purposes.
                \array_map("htmlspecialchars", [$statusCode, Status::getReason($statusCode)]),
                $errorHtml
            );
        }

        $response = new Response($statusCode, [
            "content-type" => "text/html; charset=utf-8"
        ], $this->cache[$statusCode]);

        $response->setStatus($statusCode, $reason);

        return new Success($response);
    }
}
