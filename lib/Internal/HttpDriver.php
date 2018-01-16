<?php

namespace Aerys\Internal;

use Aerys\Request;
use Aerys\Response;
use Aerys\Server;

interface HttpDriver {
    /**
     * @param Server $server
     * @param callable $onRequest
     * @param callable $onError
     * @param callable $writer
     */
    public function setup(Server $server, callable $onRequest, callable $onError, callable $writer);

    /**
     * Returns a generator used to write the response body.
     *
     * @param \Aerys\Internal\Client $client
     * @param \Aerys\Response $response
     * @param \Aerys\Request|null $request
     *
     * @return \Generator
     */
    public function writer(Client $client, Response $response, Request $request = null): \Generator;

    /**
     * Note that you *can* rely on keep-alive timeout terminating the Body with a ClientException, when no further
     * data comes in. No need to manually handle that here.
     *
     * @param \Aerys\Internal\Client $client
     *
     * @return \Generator
     */
    public function parser(Client $client): \Generator;
}
