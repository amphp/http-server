<?php

namespace Aerys\Framework;

use Auryn\Injector,
    Auryn\Provider;

class AppSettings {

    private $apps;
    private $options;
    private $injector;

    function __construct(array $apps, ServerOptions $options = NULL, Injector $injector = NULL) {
        $this->apps = $apps;
        $this->options = $options ?: new ServerOptions;
        $this->injector = $injector ?: new Provider;
    }

    function getApps() {
        return $this->apps;
    }

    function getOptions() {
        return $this->options;
    }

    function getInjector() {
        return $this->injector;
    }

}
