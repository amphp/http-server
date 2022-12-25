<?php declare(strict_types=1);

namespace Amp\Http\Server;

interface RequestHandler
{
    public function handleRequest(Request $request): Response;
}
