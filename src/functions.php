<?php

namespace Amp\Http\Server;

use Amp\Http\Status;

// Define class alias for backward compatibility.
\class_alias(HttpServer::class, Server::class);

function redirectTo(string $uri, int $statusCode = Status::FOUND): Response
{
    return new Response($statusCode, ['location' => $uri]);
}
