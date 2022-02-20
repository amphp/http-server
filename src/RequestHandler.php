<?php

namespace Amp\Http\Server;

interface RequestHandler
{
    public function handleRequest(Request $request): Response;
}
