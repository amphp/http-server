<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\Http\Status;

function redirectTo(string $uri, int $statusCode = Status::FOUND): Response
{
    return new Response($statusCode, ['location' => $uri]);
}
