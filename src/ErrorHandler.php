<?php

namespace Amp\Http\Server;

use Amp\Promise;

interface ErrorHandler
{
    /**
     * @param int          $statusCode Error status code, 4xx or 5xx.
     * @param string|null  $reason Reason message. Will use the status code's default reason if not provided.
     * @param Request|null $request Null if the error occurred before parsing the request completed.
     *
     * @return Promise
     */
    public function handleError(int $statusCode, string $reason = null, Request $request = null): Promise;
}
