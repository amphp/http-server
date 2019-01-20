<?php

namespace Amp\Http\Server;

use Amp\Promise;

interface RequestHandler
{
    /**
     * @param Request $request
     *
     * @return Promise<\Amp\Http\Server\Response>
     */
    public function handleRequest(Request $request): Promise;
}
