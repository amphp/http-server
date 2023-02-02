<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\Http\HttpStatus;

/**
 * Error handler that sends a simple HTML error page.
 */
final class DefaultErrorHandler implements ErrorHandler
{
    private static ?string $errorHtml = null;

    /** @var array<int, string> */
    private static array $cache = [];

    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response
    {
        self::$errorHtml ??= \file_get_contents(\dirname(__DIR__) . "/resources/error.html");

        $body = self::$cache[$status] ??= \str_replace(
            ["{code}", "{reason}"],
            // Using standard reason in HTML for caching purposes.
            [$status, HttpStatus::getReason($status)],
            self::$errorHtml,
        );

        $response = new Response(
            headers: [
                "content-type" => "text/html; charset=utf-8",
            ],
            body: $body,
        );

        $response->setStatus($status, $reason);

        return $response;
    }
}
