<?php declare(strict_types=1);

namespace Amp\Http\Server;

interface ErrorHandler
{
    /**
     * @param int $status Error status code, 4xx or 5xx.
     * @param string|null $reason Reason message. Will use the status code's default reason if not provided.
     * @param Request|null $request Null if the error occurred before parsing the request completed.
     */
    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response;
}
