<?php

namespace Aerys;

interface HttpDriver {
    public function versions(): array;

    public function writer(InternalRequest $ireq): \Generator;
    public function parser($callbackData): \Generator;
}