<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\Http\Status;

/**
 * ErrorHandler instance used by default if none is given.
 */
final class DefaultErrorHandler implements ErrorHandler
{
    /** @var string[] */
    private array $cache = [];

    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response
    {
        static $errorHtml;

        if ($errorHtml === null) {
            $errorHtml = \file_get_contents(\dirname(__DIR__) . "/resources/error.html");
        }

        if (!isset($this->cache[$status])) {
            $this->cache[$status] = \str_replace(
                ["{code}", "{reason}"],
                // Using standard reason in HTML for caching purposes.
                [$status, Status::getReason($status)],
                $errorHtml
            );
        }

        $response = new Response(
            headers: [
                "content-type" => "text/html; charset=utf-8",
            ],
            body: $this->cache[$status],
        );

        $response->setStatus($status, $reason);

        return $response;
    }
}
