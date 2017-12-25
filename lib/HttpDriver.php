<?php

namespace Aerys;

interface HttpDriver {
    const RESULT = 1;
    const ENTITY_HEADERS = 2;
    const ENTITY_PART = 4;
    const ENTITY_RESULT = 8;
    const SIZE_WARNING = 16;
    const ERROR = 32;

    public function setup(array $parseEmitters, callable $responseWriter);

    /**
     * @param \Aerys\Internal\Request $ireq
     * @param \Aerys\Middleware[] $userMiddlewares
     *
     * @return \Aerys\Middleware[]
     */
    public function middlewares(Internal\Request $ireq, array $userMiddlewares): array;

    /**
     * Returns a generator used to write the response body.
     *
     * @param \Aerys\Internal\Request $ireq
     * @param \Aerys\Response $response
     *
     * @return \Generator
     */
    public function writer(Internal\Request $ireq, Response $response): \Generator;

    public function upgradeBodySize(Internal\Request $ireq);

    /**
     * Note that you *can* rely on keep-alive timeout terminating the Body with a ClientException, when no further
     * data comes in. No need to manually handle that here.
     *
     * @param \Aerys\Client $client
     *
     * @return \Generator
     */
    public function parser(Client $client): \Generator;
}
