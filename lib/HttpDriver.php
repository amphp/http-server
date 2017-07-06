<?php

namespace Aerys;

interface HttpDriver {
    const RESULT = 1;
    const ENTITY_HEADERS = 2;
    const ENTITY_PART = 4;
    const ENTITY_RESULT = 8;
    const SIZE_WARNING = 16;

    public function setup(callable $parseEmitter, callable $errorEmitter, callable $responseWriter);
    public function filters(InternalRequest $ireq, array $userFilters): array;
    public function writer(InternalRequest $ireq): \Generator;
    public function upgradeBodySize(InternalRequest $ireq);
    /** Note that you *can* rely on keep-alive timeout terminating the Body with a ClientException, when no further data comes in. No need to manually handle that here. */
    public function parser(Client $client): \Generator;
}
