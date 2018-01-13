<?php

namespace Aerys;

use Amp\Promise;

interface ErrorHandler {
    /**
     * @param int $statusCode Error status code, 4xx or 5xx.
     * @param string $reason Reason message.
     * @param \Aerys\Request|null $request Null if the error occurred before parsing the request completed.
     *
     * @return \Amp\Promise
     */
    public function handle(int $statusCode, string $reason, Request $request = null): Promise;
}
