<?php

namespace Amp\Http\Server;

use Amp\Http\Status;
use Psr\Log\LoggerInterface;

/**
 * ErrorHandler instance used by default if none is given.
 */
final class DefaultErrorHandler implements ErrorHandler
{
    /** @var string[] */
    private array $cache = [];

    public function __construct()
    {
    }

    public function handleError(int $statusCode, ?string $reason = null, ?Request $request = null): Response
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
            "content-type" => "text/html; charset=utf-8",
        ], $this->cache[$statusCode]);

        $response->setStatus($statusCode, $reason);

        return $response;
    }
}
