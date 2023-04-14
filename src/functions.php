<?php declare(strict_types=1);

namespace Amp\Http\Server;

use Amp\Http\HttpStatus;

function redirectTo(string $uri, int $statusCode = HttpStatus::SEE_OTHER): Response
{
    return new Response($statusCode, ['location' => $uri]);
}
