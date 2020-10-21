<?php

namespace Amp\Http\Server;

interface RequestHandler
{
    /**
     * @param Request $request
     *
     * @return Response
     */
    public function handleRequest(Request $request): Response;
}
