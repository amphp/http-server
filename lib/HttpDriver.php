<?php

namespace Aerys;

interface HttpDriver {
    const ERROR = 1;
    const RESULT = 2;
    const ENTITY_HEADERS = 3;
    const ENTITY_PART = 4;
    const ENTITY_RESULT = 5;
    const SIZE_WARNING = 6;

    const BAD_VERSION = 1;

    public function setup(callable $parseEmitter, callable $responseWriter);
    public function filters(InternalRequest $ireq, array $userFilters): array;
    public function writer(InternalRequest $ireq): \Generator;
    public function upgradeBodySize(InternalRequest $ireq);
    /** Note that you *can* rely on keep-alive timeout terminating the Body with a ClientException, when no further data comes in. No need to manually handle that here. */
    public function parser(Client $client): \Generator;
}
