<?php

namespace Amp\Http\Server;

function redirectTo(string $uri, int $statusCode = 302): Response
{
    return new Response($statusCode, ['location' => $uri]);
}
