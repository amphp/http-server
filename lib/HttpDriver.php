<?php

namespace Aerys;

interface HttpDriver {
    const ERROR = 1;
    const RESULT = 2;
    const ENTITY_HEADERS = 3;
    const ENTITY_PART = 4;
    const ENTITY_RESULT = 5;

    const BAD_VERSION = 1;

    public function setup(callable $parseEmitter, callable $responseWriter);
    public function filters(InternalRequest $ireq): array;
    public function writer(InternalRequest $ireq): \Generator;
    public function parser(Client $client): \Generator;
}
