<?php

namespace Amp\Http\Server;

use Amp\Http\Status;

function redirectTo(string $uri, int $statusCode = Status::FOUND): Response
{
    return new Response($statusCode, ['location' => $uri]);
}
