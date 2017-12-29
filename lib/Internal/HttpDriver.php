<?php

namespace Aerys\Internal;

use Aerys\Response;

interface HttpDriver {
    const RESULT = 1;
    const ENTITY_HEADERS = 2;
    const ENTITY_PART = 4;
    const ENTITY_RESULT = 8;
    const SIZE_WARNING = 16;
    const ERROR = 32;

    /**
     * @param \Amp\Emitter[] $parseEmitters
     * @param callable $responseWriter
     */
    public function setup(array $parseEmitters, callable $responseWriter);

    /**
     * Returns a generator used to write the response body.
     *
     * @param \Aerys\Internal\Request $request
     * @param \Aerys\Response $response
     *
     * @return \Generator
     */
    public function writer(Request $request, Response $response): \Generator;

    /**
     * @param \Aerys\Internal\Request $ireq
     */
    public function upgradeBodySize(Request $ireq);

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
