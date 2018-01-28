<?php

namespace Aerys;

final class RouteArguments {
    private $args;

    public function __construct(array $args) {
        $this->args = $args;
    }

    public function get(string $key) {
        return $this->args[$key] ?? null;
    }

    public function getAll(): array {
        return $this->args;
    }
}