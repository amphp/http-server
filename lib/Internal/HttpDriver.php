<?php

namespace Aerys\Internal;

use Aerys\Response;

interface HttpDriver {
    const RESULT = 1;
    const ENTITY_HEADERS = 2;
    const ENTITY_PART = 4;
    const ENTITY_RESULT = 8;
    const ERROR = 16;

    /**
     * @param \Amp\Emitter[] $parseEmitters
     * @param callable $responseWriter
     */
    public function setup(array $parseEmitters, callable $responseWriter);

    /**
     * Returns a generator used to write the response body.
     *
     * @param \Aerys\Internal\ServerRequest $request
     * @param \Aerys\Response $response
     *
     * @return \Generator
     */
    public function writer(ServerRequest $request, Response $response): \Generator;

    /**
     * @param \Aerys\Internal\ServerRequest $ireq
     * @param int $bodySize
     */
    public function upgradeBodySize(ServerRequest $ireq, int $bodySize);

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
